<?php

declare(strict_types=1);

namespace App\Tests\Service\SpaApi\Documents;

use App\Entity\Document\Document;
use App\Entity\User\User;
use App\Message\NotificationMessage;
use App\Service\Notification\NotificationPublisher;
use App\Service\SpaApi\Documents\DocumentNotifier;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class DocumentNotifierTest extends TestCase
{
    /** @var list<NotificationMessage> */
    private array $messages = [];

    private DocumentNotifier $notifier;

    protected function setUp(): void
    {
        $this->messages = [];

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            function (object $message): Envelope {
                if ($message instanceof NotificationMessage) {
                    $this->messages[] = $message;
                }

                return new Envelope($message);
            },
        );

        $this->notifier = new DocumentNotifier(new NotificationPublisher($bus));
    }

    public function testEditedTitleNamesTheFile(): void
    {
        $this->notifier->notifyEdited($this->document(), $this->actor(), fileName: 'записка.docx');

        self::assertSame(
            'Еськов Сергей внёс правки в файл «записка.docx» документа демо-Записка-9',
            $this->messages[0]->title,
        );
    }

    public function testEditedTitleWithoutFileStaysOnTheDocument(): void
    {
        $this->notifier->notifyEdited($this->document(), $this->actor(), fileName: '   ');

        self::assertSame(
            'Еськов Сергей внёс правки в документ демо-Записка-9',
            $this->messages[0]->title,
        );
    }

    private function document(): Document
    {
        $author = $this->createMock(User::class);
        $author->method('getId')->willReturn(1);

        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn(9);
        $document->method('getName')->willReturn('демо-Записка-9');
        $document->method('getCreatedBy')->willReturn($author);
        $document->method('getUserRecipients')->willReturn(new ArrayCollection());

        return $document;
    }

    private function actor(): User
    {
        $actor = $this->createMock(User::class);
        $actor->method('getId')->willReturn(2);
        $actor->method('getLastname')->willReturn('Еськов');
        $actor->method('getFirstname')->willReturn('Сергей');

        return $actor;
    }
}
