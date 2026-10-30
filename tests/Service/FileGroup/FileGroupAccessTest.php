<?php

declare(strict_types=1);

namespace App\Tests\Service\FileGroup;

use App\Entity\FileGroup\FileGroup;
use App\Entity\FileGroup\FileGroupFile;
use App\Entity\User\User;
use App\Service\FileGroup\FileGroupAccess;
use PHPUnit\Framework\TestCase;

final class FileGroupAccessTest extends TestCase
{
    public function testOwnerUploadsAndWritesEverything(): void
    {
        $access = new FileGroupAccess();
        $owner = $this->user(1);
        $group = new FileGroup($owner, 'Группа');
        $file = new FileGroupFile($group, 'a.docx', 'a.docx', 10, null);

        self::assertTrue($access->canUpload($group, $owner));
        self::assertTrue($access->canWrite($file, $owner));
    }

    public function testMemberUploadsOnlyWhenOwnerAllowsAndEditsOwnFile(): void
    {
        $access = new FileGroupAccess();
        $owner = $this->user(1);
        $author = $this->user(2);
        $other = $this->user(3);
        $group = new FileGroup($owner, 'Группа');
        $group->addMember($author);
        $group->addMember($other);

        self::assertFalse($access->canUpload($group, $author));
        $group->addUploader($author);
        self::assertTrue($access->canUpload($group, $author));
        self::assertFalse($access->canUpload($group, $other));

        $file = new FileGroupFile($group, 'a.docx', 'a.docx', 10, null);
        $file->setUploadedBy($author);
        $file->addWriter($author);

        self::assertTrue($access->canWrite($file, $author));
        self::assertFalse($access->canWrite($file, $other));

        $file->addWriter($other);
        self::assertTrue($access->canWrite($file, $other));

        $group->removeMember($author);
        self::assertFalse($access->canUpload($group, $author));
        self::assertFalse($access->canWrite($file, $author));
    }

    private function user(int $id): User
    {
        $user = new User();
        $user->setLogin('user' . $id);
        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);

        return $user;
    }
}
