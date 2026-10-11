<?php

declare(strict_types=1);

namespace App\Service\OnlyOffice;

use App\Entity\Document\Document;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class LegacyEditorSession
{
    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        private readonly EntityManagerInterface $em,
        #[Autowire('%private_upload_dir_documents_originals%')]
        private readonly string $originalsDir,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function create(User $user, ?Document $document, string $source, bool $fromTemplate): array
    {
        $id = bin2hex(random_bytes(32));
        $filename = $fromTemplate ? $id . '.docx' : (string) $document?->getOriginalFile();
        $path = $this->path($filename);
        if ($fromTemplate && !copy($source, $path)) {
            throw new \RuntimeException('Cannot prepare OnlyOffice document.');
        }
        $session = [
            'id' => $id, 'key' => 'doc-' . $id, 'filename' => $filename,
            'userId' => $user->getId(), 'documentId' => $document?->getId(),
            'originalFile' => $document?->getOriginalFile(), 'fromTemplate' => $fromTemplate,
            'expires' => time() + 3600,
        ];
        $item = $this->cache->getItem('legacy_editor_' . $id);
        $item->set($session)->expiresAt(new \DateTimeImmutable('@' . $session['expires']));
        $this->cache->save($item);

        return $session;
    }

    public function get(string $id): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $id)) {
            return null;
        }
        $item = $this->cache->getItem('legacy_editor_' . $id);
        $session = $item->isHit() ? $item->get() : null;
        if (!is_array($session) || $session['expires'] <= time()) {
            return null;
        }
        $user = $this->em->find(User::class, $session['userId']);
        if (!$user instanceof User || $user->isDeleted()) {
            return null;
        }
        if ($session['documentId'] !== null) {
            $document = $this->em->find(Document::class, $session['documentId']);
            if (!$document instanceof Document || $document->getOriginalFile() !== $session['originalFile']
                || ($document->getCreatedBy()?->getId() !== $user->getId() && !in_array('ROLE_ADMIN', $user->getRoles(), true))) {
                return null;
            }
        }

        return $session;
    }

    public function path(string $filename): string
    {
        $directory = realpath($this->originalsDir);
        $public = realpath($this->projectDir . '/public');
        if ($directory === false || $public === false || basename($filename) !== $filename || $filename === '') {
            throw new ServiceUnavailableHttpException();
        }
        $normalized = strtolower(str_replace('\\', '/', $directory));
        $public = strtolower(str_replace('\\', '/', $public));
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        if ($normalized === $public || str_starts_with($normalized, $public . '/') || is_link($path)) {
            throw new ServiceUnavailableHttpException();
        }

        return $path;
    }

    public function beginSave(string $id): string
    {
        $saveId = bin2hex(random_bytes(16));
        $item = $this->cache->getItem('legacy_editor_save_' . $id . '_' . $saveId);
        $item->set(false)->expiresAfter(120);
        $this->cache->save($item);

        return $saveId;
    }

    public function acknowledgeSave(string $id, string $saveId): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $saveId)) {
            return;
        }
        $item = $this->cache->getItem('legacy_editor_save_' . $id . '_' . $saveId);
        if ($item->isHit()) {
            $item->set(true);
            $this->cache->save($item);
        }
    }

    public function isSaved(string $id, string $saveId): bool
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $saveId)) {
            return false;
        }
        $item = $this->cache->getItem('legacy_editor_save_' . $id . '_' . $saveId);

        return $item->isHit() && $item->get() === true;
    }
}
