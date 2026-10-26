<?php

declare(strict_types=1);

namespace App\Tests\Service\SpaApi\Documents;

use App\Entity\Document\Document;
use App\Entity\Document\DocumentComment;
use App\Entity\Document\DocumentUserRecipient;
use App\Entity\User\User;
use App\Enum\Document\DocumentRecipientRole;
use App\Enum\Document\DocumentStatus;
use App\Service\SpaApi\Documents\DocumentAccessService;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class DocumentAccessServiceTest extends TestCase
{
    public function testAnyoneWhoCanViewTheDocumentCanEditCommentFile(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(false);
        $access = new DocumentAccessService($security);

        $author = $this->createUser(1);
        $executor = $this->createUser(2);
        $recipientOnly = $this->createUser(3);
        $outsider = $this->createUser(4);

        $document = new Document();
        $document->setName('doc');
        $document->setStatus(DocumentStatus::NEW);
        $document->setCreatedBy($author);
        $this->addParticipant($document, $executor, DocumentRecipientRole::EXECUTOR);
        $this->addParticipant($document, $recipientOnly, DocumentRecipientRole::RECIPIENT);

        $comment = new DocumentComment();
        $comment->setDocument($document);
        $comment->setAuthor($author);

        self::assertTrue($access->canEditCommentFile($comment, $author));
        self::assertTrue($access->canManageComment($comment, $author));

        self::assertTrue($access->canEditCommentFile($comment, $executor));
        self::assertFalse($access->canManageComment($comment, $executor));

        self::assertTrue($access->canEditCommentFile($comment, $recipientOnly));
        self::assertFalse($access->canManageComment($comment, $recipientOnly));

        self::assertFalse($access->canEditCommentFile($comment, $outsider));
        self::assertFalse($access->canManageComment($comment, $outsider));
    }

    private function createUser(int $id): User
    {
        $user = new User();
        $user->setLogin('user' . $id);
        $user->setEmail('user' . $id . '@example.com');
        $user->setFirstname('First');
        $user->setLastname('Last');

        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);

        return $user;
    }

    private function addParticipant(Document $document, User $user, DocumentRecipientRole $role): void
    {
        $recipient = new DocumentUserRecipient();
        $recipient->setDocument($document);
        $recipient->setUser($user);
        $recipient->setRole($role);
        $recipient->setStatus(DocumentStatus::NEW);
        $document->addUserRecipient($recipient);
    }
}
