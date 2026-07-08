<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User\User;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class UserAvatarStorageService
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly S3Client $s3,
        private readonly string $bucket,
    ) {
    }

    public function upload(User $user, UploadedFile $file): string
    {
        $userId = $user->getId();
        if ($userId === null) {
            throw new \InvalidArgumentException('Нельзя загрузить аватар для пользователя без ID.');
        }

        $mimeType = $file->getMimeType() ?: $file->getClientMimeType();
        if ($mimeType === null || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException('Допустимые форматы: JPG, PNG, GIF, WebP.');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension === '') {
            $extension = match ($mimeType) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => 'bin',
            };
        }

        $storageKey = sprintf('%d/avatar/%s.%s', $userId, bin2hex(random_bytes(16)), $extension);

        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $storageKey,
            'SourceFile' => $file->getPathname(),
            'ContentType' => $mimeType,
        ]);

        return $storageKey;
    }

    public function delete(?string $storageKey): void
    {
        if ($storageKey === null || $storageKey === '') {
            return;
        }

        try {
            $this->s3->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $storageKey,
            ]);
        } catch (S3Exception) {
            // Файл уже удалён или не существует — это не должно блокировать замену аватара.
        }
    }

    public function exists(string $storageKey): bool
    {
        try {
            $this->s3->headObject([
                'Bucket' => $this->bucket,
                'Key' => $storageKey,
            ]);

            return true;
        } catch (S3Exception) {
            return false;
        }
    }

    public function getObject(string $storageKey): Result
    {
        return $this->s3->getObject([
            'Bucket' => $this->bucket,
            'Key' => $storageKey,
        ]);
    }

    public function getObjectStream(string $storageKey): StreamInterface
    {
        $result = $this->getObject($storageKey);

        return $result['Body'];
    }
}
