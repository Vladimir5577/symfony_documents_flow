<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Document\Document;
use App\Entity\Document\DocumentUserRecipient;
use App\Entity\User\User;
use App\Enum\Document\DocumentRecipientRole;
use App\Enum\Document\DocumentStatus;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DocumentRecipientsService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<mixed> $ids
     *
     * @return list<int>
     */
    public function normalizeUserIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(static fn ($id) => (int) $id, $ids), static fn (int $id) => $id > 0)));
    }

    /**
     * @param list<int> $executorUserIds
     * @param list<int> $recipientUserIds
     */
    public function attachRecipients(Document $document, array $executorUserIds, array $recipientUserIds): void
    {
        $now = new \DateTimeImmutable();

        foreach ($executorUserIds as $userId) {
            $user = $this->userRepository->findActive($userId);
            if ($user === null) {
                // Неизвестный или уволенный сотрудник в запросе — ошибка, а не
                // молчаливый пропуск: иначе состав мог опустеть «успешно».
                throw new DocumentRecipientsException(SpaApiError::USER_NOT_FOUND);
            }

            $recipient = new DocumentUserRecipient();
            $recipient->setDocument($document);
            $recipient->setUser($user);
            $recipient->setRole(DocumentRecipientRole::EXECUTOR);
            $recipient->setStatus(DocumentStatus::NEW);
            $recipient->setCreatedAt($now);
            $recipient->setUpdatedAt($now);
            $document->addUserRecipient($recipient);
            $this->entityManager->persist($recipient);
        }

        foreach ($recipientUserIds as $userId) {
            $user = $this->userRepository->findActive($userId);
            if ($user === null) {
                continue;
            }

            $recipient = new DocumentUserRecipient();
            $recipient->setDocument($document);
            $recipient->setUser($user);
            $recipient->setRole(DocumentRecipientRole::RECIPIENT);
            $recipient->setStatus(DocumentStatus::NEW);
            $recipient->setCreatedAt($now);
            $recipient->setUpdatedAt($now);
            $document->addUserRecipient($recipient);
            $this->entityManager->persist($recipient);
        }
    }

    /**
     * Сменить состав участников diff-ом по ключу «пользователь|роль».
     *
     * Раньше состав сносился целиком и создавался заново со статусом NEW (BE-12):
     * у исполнителя, уже выставившего APPROVED, согласование обнулялось, документ
     * снова становился «Новым», а промежуточный flush между удалением и созданием
     * при сбое оставлял опубликованный документ вовсе без получателей. Теперь
     * совпавшие строки не трогаются (статус, даты сохраняются), удаляются только
     * выбывшие, создаются только новые — в одной транзакции вызывающего.
     *
     * @param list<int> $executorUserIds
     * @param list<int> $recipientUserIds
     *
     * @return array{added: list<User>, removed: list<User>} кого добавили и кого сняли —
     *         вызывающий уведомляет добавленных и пишет историю
     */
    public function replaceRecipients(Document $document, array $executorUserIds, array $recipientUserIds): array
    {
        $executorUserIds = $this->normalizeUserIds($executorUserIds);
        $recipientUserIds = $this->normalizeUserIds($recipientUserIds);

        // Опубликованный документ без единого участника не имеет адресата:
        // такой запрос отклоняем до того, как что-то снято.
        if ($document->isPublished() && $executorUserIds === [] && $recipientUserIds === []) {
            throw new DocumentRecipientsException(SpaApiError::DOCUMENT_NO_RECIPIENTS);
        }

        $wanted = [];
        foreach ($executorUserIds as $userId) {
            $wanted[$userId . '|' . DocumentRecipientRole::EXECUTOR->value] = [$userId, DocumentRecipientRole::EXECUTOR];
        }
        foreach ($recipientUserIds as $userId) {
            $wanted[$userId . '|' . DocumentRecipientRole::RECIPIENT->value] = [$userId, DocumentRecipientRole::RECIPIENT];
        }

        $removed = [];
        $existingKeys = [];
        foreach ($document->getUserRecipients()->toArray() as $recipient) {
            $user = $recipient->getUser();
            if ($user === null) {
                continue;
            }
            $key = $user->getId() . '|' . $recipient->getRole()->value;
            if (array_key_exists($key, $wanted)) {
                $existingKeys[$key] = true;
                continue;
            }
            $document->removeUserRecipient($recipient);
            $this->entityManager->remove($recipient);
            $removed[$user->getId()] = $user;
        }

        $added = [];
        $now = new \DateTimeImmutable();
        foreach ($wanted as $key => [$userId, $role]) {
            if (isset($existingKeys[$key])) {
                continue;
            }
            $user = $this->userRepository->findActive($userId);
            if ($user === null) {
                continue;
            }

            $recipient = new DocumentUserRecipient();
            $recipient->setDocument($document);
            $recipient->setUser($user);
            $recipient->setRole($role);
            $recipient->setStatus(DocumentStatus::NEW);
            $recipient->setCreatedAt($now);
            $recipient->setUpdatedAt($now);
            $document->addUserRecipient($recipient);
            $this->entityManager->persist($recipient);
            $added[$user->getId()] = $user;
        }

        if ($added !== [] || $removed !== []) {
            $document->setUpdatedAt($now);
        }

        return ['added' => array_values($added), 'removed' => array_values($removed)];
    }
}
