<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\Upd;
use App\Entity\Inventory\UpdFile;
use App\Entity\User\User;
use App\Repository\Inventory\UpdFileRepository;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Файлы УПД в MinIO: скан, pdf, docx, фотография.
 *
 * Содержимое лежит в своём бакете, в базе только ключ объекта. Тип файла проверяется
 * и по расширению, и по сигнатуре: УПД — бухгалтерский документ, принимать под видом
 * pdf что угодно нельзя.
 */
final class UpdFileService
{
    /**
     * Потолок на файл — ниже, чем в php.ini (там upload_max_filesize 50M при
     * post_max_size 52M), и это намеренно. Если запрос перевалит за post_max_size,
     * PHP выбросит тело целиком: $_FILES приедет пустым, и приложению будет нечем
     * объяснить отказ. Свой лимит срабатывает раньше и отвечает внятным кодом.
     * 25 МБ хватает на постраничный скан в 300 dpi.
     */
    private const MAX_SIZE_BYTES = 25 * 1024 * 1024;

    private const ALLOWED_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'docx', 'xlsx'];

    /**
     * Расширение подделывается переименованием, поэтому сверяем ещё и сигнатуру файла.
     * docx и xlsx — zip-контейнеры, у них общее начало, и дальше сигнатуры мы не идём:
     * задача не отличить один офисный формат от другого, а не пустить исполняемое.
     */
    private const MAGIC_BYTES = [
        'pdf' => '%PDF',
        'png' => "\x89PNG",
        'jpg' => "\xFF\xD8\xFF",
        'jpeg' => "\xFF\xD8\xFF",
        'webp' => 'RIFF',
        'docx' => "PK\x03\x04",
        'xlsx' => "PK\x03\x04",
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UpdFileRepository $fileRepository,
        private readonly S3Client $s3,
        private readonly string $bucket,
    ) {
    }

    /**
     * Кладёт файл в бакет и заводит строку. Порядок важен: сначала объект, потом
     * запись. В обратном порядке при сбое осталась бы строка, ссылающаяся в пустоту,
     * и скачивание ломалось бы молча; при этом — худшее, что бывает, это осиротевший
     * объект в бакете, который никому не мешает.
     *
     * @return array{0: UpdFile|null, 1: string|null} файл либо код ошибки из SpaApiError
     */
    public function upload(UploadedFile $file, Upd $upd, ?User $uploadedBy): array
    {
        $error = $this->validate($file);
        if ($error !== null) {
            return [null, $error];
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $contentType = $file->getClientMimeType() ?: 'application/octet-stream';
        $storageKey = sprintf('%d/%s.%s', $upd->getId(), bin2hex(random_bytes(16)), $extension);

        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $storageKey,
            'SourceFile' => $file->getPathname(),
            'ContentType' => $contentType,
        ]);

        $updFile = new UpdFile();
        $updFile->setUpd($upd);
        // Имя приходит от пользователя, а колонка 255 — длинное обрезаем, иначе вставка упадёт.
        $updFile->setFilename(mb_substr($file->getClientOriginalName(), 0, 255));
        $updFile->setStorageKey($storageKey);
        $updFile->setContentType(mb_substr($contentType, 0, 100));
        $updFile->setSizeBytes((int) $file->getSize());
        $updFile->setUploadedBy($uploadedBy);

        $this->em->persist($updFile);
        $this->em->flush();

        return [$updFile, null];
    }

    public function delete(UpdFile $file): void
    {
        $this->deleteObject($file->getStorageKey());

        $this->em->remove($file);
        $this->em->flush();
    }

    /**
     * Снести файлы документа перед удалением самого документа.
     *
     * Внешний ключ стоит CASCADE, то есть строки уедут сами — а объекты остались бы
     * висеть в бакете навсегда, и найти их потом было бы уже нечем: ключи хранятся
     * только в этих строках.
     */
    public function deleteAllForUpd(Upd $upd): void
    {
        foreach ($this->fileRepository->findByUpd($upd) as $file) {
            $this->deleteObject($file->getStorageKey());
            $this->em->remove($file);
        }

        $this->em->flush();
    }

    /**
     * Поток с содержимым файла — им отдаём скачивание, не поднимая файл в память.
     */
    public function getObjectStream(UpdFile $file): StreamInterface
    {
        $result = $this->s3->getObject([
            'Bucket' => $this->bucket,
            'Key' => $file->getStorageKey(),
        ]);

        return $result['Body'];
    }

    public function exists(UpdFile $file): bool
    {
        try {
            $this->s3->headObject([
                'Bucket' => $this->bucket,
                'Key' => $file->getStorageKey(),
            ]);

            return true;
        } catch (S3Exception) {
            return false;
        }
    }

    /**
     * @return string|null код ошибки из SpaApiError, если файл принимать нельзя
     */
    private function validate(UploadedFile $file): ?string
    {
        // Файл крупнее upload_max_filesize приезжает с кодом ошибки вместо содержимого.
        if (!$file->isValid()) {
            return $file->getError() === \UPLOAD_ERR_INI_SIZE
                ? SpaApiError::INVENTORY_FILE_TOO_LARGE
                : SpaApiError::INVENTORY_FILE_UPLOAD_FAILED;
        }

        if ((int) $file->getSize() > self::MAX_SIZE_BYTES) {
            return SpaApiError::INVENTORY_FILE_TOO_LARGE;
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return SpaApiError::INVENTORY_FILE_TYPE_NOT_ALLOWED;
        }

        if (!$this->matchesMagicBytes($file, $extension)) {
            return SpaApiError::INVENTORY_FILE_TYPE_NOT_ALLOWED;
        }

        return null;
    }

    private function matchesMagicBytes(UploadedFile $file, string $extension): bool
    {
        $expected = self::MAGIC_BYTES[$extension] ?? null;
        if ($expected === null) {
            return true;
        }

        $handle = @fopen($file->getPathname(), 'rb');
        if ($handle === false) {
            return false;
        }

        $header = fread($handle, strlen($expected));
        fclose($handle);

        return $header !== false && str_starts_with($header, $expected);
    }

    /**
     * Объекта может уже не быть — например, бакет чистили руками. Это не повод
     * отказываться удалять строку: иначе она останется навсегда неудаляемой.
     */
    private function deleteObject(string $storageKey): void
    {
        try {
            $this->s3->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $storageKey,
            ]);
        } catch (S3Exception) {
            // Уже удалён или недоступен — продолжаем.
        }
    }
}
