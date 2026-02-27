<?php

namespace App\Service\Kanban;

use App\Entity\Kanban\KanbanAttachment;
use App\Entity\Kanban\KanbanCard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

class KanbanAttachmentService
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'docx', 'xlsx'];

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
        private readonly string $kanbanUploadDir,
    ) {
    }

    public function upload(UploadedFile $file, KanbanCard $card): KanbanAttachment
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw new UnsupportedMediaTypeHttpException(
                sprintf('Тип файла .%s не поддерживается. Разрешены: %s', $ext, implode(', ', self::ALLOWED_EXTENSIONS))
            );
        }

        $this->validateMagicBytes($file, $ext);

        $storageKey = sprintf('%s/%s.%s', $card->getId(), bin2hex(random_bytes(16)), $ext);
        $targetDir = $this->kanbanUploadDir . '/' . dirname($storageKey);

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0o755, true);
        }

        $file->move($targetDir, basename($storageKey));

        $attachment = new KanbanAttachment();
        $attachment->setFilename($file->getClientOriginalName());
        $attachment->setStorageKey($storageKey);
        $attachment->setContentType($file->getClientMimeType() ?: 'application/octet-stream');
        $attachment->setSizeBytes($file->getSize() ?: 0);
        $attachment->setCard($card);

        $this->em->persist($attachment);
        $this->em->flush();

        return $attachment;
    }

    public function getFilePath(KanbanAttachment $attachment): string
    {
        return $this->kanbanUploadDir . '/' . $attachment->getStorageKey();
    }

    public function delete(KanbanAttachment $attachment): void
    {
        $path = $this->getFilePath($attachment);
        if (file_exists($path)) {
            unlink($path);
        }

        $this->em->remove($attachment);
        $this->em->flush();
    }

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
}
