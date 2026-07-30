<?php

declare(strict_types=1);

namespace App\Service\Analytics\TKO;

use App\Entity\Analytics\TKO\AnalyticsTkoVehicle;
use App\Entity\Analytics\TKO\AnalyticsTkoVehicleAttachment;
use App\Entity\User\User;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class AnalyticsTkoVehicleAttachmentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly S3Client $s3,
        private readonly string $bucket,
    ) {
    }

    public function upload(
        UploadedFile $file,
        AnalyticsTkoVehicle $vehicle,
        string $context,
        ?User $author = null,
    ): AnalyticsTkoVehicleAttachment {
        $ext = strtolower($file->getClientOriginalExtension());
        if ('' === $ext) {
            $ext = strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION)) ?: 'bin';
        }

        $originalName = $file->getClientOriginalName();
        $contentType = $file->getClientMimeType() ?: 'application/octet-stream';
        $sizeBytes = $file->getSize() ?: 0;

        $storageKey = sprintf('tko-vehicle/%s/%s.%s', $vehicle->getId(), bin2hex(random_bytes(16)), $ext);

        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $storageKey,
            'SourceFile' => $file->getPathname(),
            'ContentType' => $contentType,
        ]);

        $attachment = new AnalyticsTkoVehicleAttachment();
        $attachment->setFilename($originalName);
        $attachment->setStorageKey($storageKey);
        $attachment->setContentType($contentType);
        $attachment->setSizeBytes($sizeBytes);
        $attachment->setContext($context);
        $attachment->setVehicle($vehicle);
        $attachment->setAuthor($author);

        $this->em->persist($attachment);
        $this->em->flush();

        return $attachment;
    }

    public function getObjectStream(AnalyticsTkoVehicleAttachment $attachment): StreamInterface
    {
        $result = $this->s3->getObject([
            'Bucket' => $this->bucket,
            'Key' => $attachment->getStorageKey(),
        ]);

        return $result['Body'];
    }

    public function delete(AnalyticsTkoVehicleAttachment $attachment): void
    {
        $storageKey = $attachment->getStorageKey();

        $this->em->remove($attachment);
        $this->em->flush();

        try {
            $this->s3->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $storageKey,
            ]);
        } catch (S3Exception) {
            // Запись уже удалена; сирота в S3 допустима.
        }
    }
}
