<?php

declare(strict_types=1);

namespace App\Service\FileGroup;

use App\Entity\FileGroup\FileGroup;
use App\Entity\FileGroup\FileGroupFile;
use App\Entity\User\User;

final class FileGroupAccess
{
    public function isOwner(FileGroup $group, User $user): bool
    {
        return $group->getOwner()->getId() === $user->getId();
    }

    public function canRead(FileGroup $group, User $user): bool
    {
        return $this->isOwner($group, $user) || $group->getMembers()->contains($user);
    }

    public function canManage(FileGroup $group, User $user): bool
    {
        return $this->isOwner($group, $user);
    }

    public function canUpload(FileGroup $group, User $user): bool
    {
        return $this->isOwner($group, $user) || $group->getUploaders()->contains($user);
    }

    public function canWrite(FileGroupFile $file, User $user): bool
    {
        $group = $file->getGroup();
        if (!$this->canRead($group, $user)) {
            return false;
        }

        $author = $file->getUploadedBy();

        return $this->isOwner($group, $user)
            || ($author instanceof User && $author->getId() === $user->getId())
            || $file->getWriters()->contains($user);
    }

    /** @return array{id: int, fullName: string} */
    public function presentUser(User $user): array
    {
        $name = trim(implode(' ', array_filter(
            [$user->getLastname(), $user->getFirstname(), $user->getPatronymic()],
            static fn (?string $part): bool => $part !== null && trim($part) !== '',
        )));

        return [
            'id' => (int) $user->getId(),
            'fullName' => $name !== '' ? $name : (string) $user->getLogin(),
        ];
    }
}
