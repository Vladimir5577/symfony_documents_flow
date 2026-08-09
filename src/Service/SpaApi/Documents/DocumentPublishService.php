<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\User\User;
use App\Enum\Document\DocumentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class DocumentPublishService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentAccessService $accessService,
        private readonly DocumentNotifier $documentNotifier,
    ) {
    }

    public function publish(Document $document, User $currentUser): Document
    {
        if (!$this->accessService->canEditOutgoingDocument($document, $currentUser)) {
            throw new AccessDeniedHttpException(SpaApiError::ACCESS_DENIED);
        }

        if ($document->getStatus() === DocumentStatus::DRAFT) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_CANNOT_PUBLISH_DRAFT);
        }

        if ($document->getUserRecipients()->isEmpty()) {
            throw new BadRequestHttpException(SpaApiError::DOCUMENT_NO_RECIPIENTS);
        }

        if ($document->isPublished()) {
            return $document;
        }

        $recipientsById = [];
        foreach ($document->getUserRecipients() as $recipient) {
            $user = $recipient->getUser();
            if ($user !== null) {
                $recipientsById[$user->getId()] = $user;
            }
        }

        // Публикация документа и постановка уведомления в outbox — одна
        // транзакция. Раньше между ними был коммит: документ становился
        // опубликованным, а событие могло не уехать. Повторно опубликовать
        // не выйдет — isPublished уже стоит, и метод выходит раньше, — так
        // что уведомление терялось безвозвратно.
        //
        // Оба действия пишут в одну базу через одно соединение, поэтому
        // общая транзакция делает их неразделимыми: либо документ
        // опубликован и событие лежит в outbox, либо ни того, ни другого.
        $this->entityManager->wrapInTransaction(function () use ($document, $recipientsById): void {
            $document->setIsPublished(true);
            $document->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->documentNotifier->notifyIncoming($document, array_values($recipientsById));
        });

        return $document;
    }
}
