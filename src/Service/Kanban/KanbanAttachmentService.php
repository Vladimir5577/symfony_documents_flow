<?php

namespace App\Service\Kanban;

use App\Entity\Kanban\KanbanAttachment;
use App\Entity\Kanban\KanbanCard;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
// [KANBAN: валидация типов файла отключена] Раскомментируйте вместе с блоками ниже.
// use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

class KanbanAttachmentService
{
    /** Используются вместе с раскомментированной проверкой (validateMagicBytes ниже). */
    private const ALLOWED_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'docx', 'xlsx'];

    /** Используются вместе с раскомментированной проверкой (validateMagicBytes ниже). */
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
        private readonly S3Client $s3,
        private readonly string $bucket,
    ) {
    }

    public function upload(UploadedFile $file, KanbanCard $card): KanbanAttachment
    {
        $ext = strtolower($file->getClientOriginalExtension());
        // --- [KANBAN: валидация типов файла — ВРЕМЕННО ОТКЛЮЧЕНА] (раскомментировать use UnsupportedMediaTypeHttpException сверху) ---
        /*
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw new UnsupportedMediaTypeHttpException(
                sprintf('Тип файла .%s не поддерживается. Разрешены: %s', $ext, implode(', ', self::ALLOWED_EXTENSIONS))
            );
        }

        $this->validateMagicBytes($file, $ext);
        */
        // Раньше пустое расширение не доходило сюда; для ключа хранения нужна непустая «метка».
        if ($ext === '') {
            $ext = strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION)) ?: 'bin';
        }

        // Capture metadata before reading the temp file
        $originalName = $file->getClientOriginalName();
        $contentType = $file->getClientMimeType() ?: 'application/octet-stream';
        $sizeBytes = $file->getSize() ?: 0;

        $storageKey = sprintf('%s/%s.%s', $card->getId(), bin2hex(random_bytes(16)), $ext);

        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $storageKey,
            'SourceFile' => $file->getPathname(),
            'ContentType' => $contentType,
        ]);

        $attachment = new KanbanAttachment();
        $attachment->setFilename($originalName);
        $attachment->setStorageKey($storageKey);
        $attachment->setContentType($contentType);
        $attachment->setSizeBytes($sizeBytes);
        $attachment->setCard($card);

        $this->em->persist($attachment);
        $this->em->flush();

        return $attachment;
    }

    /**
     * Возвращает поток (PSR-7 StreamInterface) с содержимым файла из S3.
     */
    public function getObjectStream(KanbanAttachment $attachment): StreamInterface
    {
        $result = $this->s3->getObject([
            'Bucket' => $this->bucket,
            'Key' => $attachment->getStorageKey(),
        ]);

        return $result['Body'];
    }

    /**
     * Проверяет, существует ли файл в S3.
     */
    public function exists(KanbanAttachment $attachment): bool
    {
        try {
            $this->s3->headObject([
                'Bucket' => $this->bucket,
                'Key' => $attachment->getStorageKey(),
            ]);

            return true;
        } catch (S3Exception) {
            return false;
        }
    }

    public function flush(): void
    {
        $this->em->flush();
    }

    public function delete(KanbanAttachment $attachment): void
    {
        try {
            $this->s3->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $attachment->getStorageKey(),
            ]);
        } catch (S3Exception) {
            // Файл уже удалён или не существует — продолжаем удаление записи из БД.
        }

        $this->em->remove($attachment);
        $this->em->flush();
    }

    // --- [KANBAN: валидация magic bytes — ВРЕМЕННО ОТКЛЮЧЕНА] (вместе с вызовом в upload()) ---
    /*
    private function validateMagicBytes(UploadedFile $file, string $ext): void
    {
        $expected = self::MAGIC_BYTES[$ext] ?? null;
        if ($expected === null) {
            return;
        }

        $handle = fopen($file->getPathname(), 'rb');
        if (!$handle) {
            throw new UnsupportedMediaTypeHttpException('Не удалось прочитать файл.');
        }

        $header = fread($handle, max(8, strlen($expected)));
        fclose($handle);

        if ($header === false || !str_starts_with($header, $expected)) {
            throw new UnsupportedMediaTypeHttpException(
                sprintf('Содержимое файла не соответствует расширению .%s', $ext)
            );
        }
    }
    */
}
