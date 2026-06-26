<?php

declare(strict_types=1);

namespace App\Service\Kanban;

use App\Entity\Kanban\KanbanCard;
use App\Entity\User\User;
use App\Service\User\UserAvatarUrlGenerator;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Единая точка публикации realtime-событий доски в Mercure.
 *
 * Все события карточек летят в топик /kanban/board/{boardId} и применяются на
 * фронте через boardCardPatched (частичный мёрж). Поля формируются строго в
 * формате KanbanItem (см. BoardController::formatColumn), чтобы то, что приходит
 * по сокету, совпадало с тем, что отдаёт endpoint доски.
 *
 * senderId в каждом событии позволяет автору игнорировать собственное эхо.
 */
final class KanbanRealtimePublisher
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly UserAvatarUrlGenerator $userAvatarUrlGenerator,
    ) {
    }

    /**
     * Публикует частичное обновление карточки.
     *
     * $card — патч: всегда содержит 'id', плюс только реально изменённые поля
     * формата KanbanItem. Неуказанные поля на фронте остаются без изменений.
     *
     * @param array<string, mixed> $card
     */
    public function publishCardUpdated(int $boardId, array $card, int $senderId): void
    {
        $this->hub->publish(new Update(
            '/kanban/board/' . $boardId,
            json_encode([
                'type' => 'card_updated',
                'card' => $card,
                'senderId' => $senderId,
            ], JSON_THROW_ON_ERROR),
        ));
    }

    /**
     * Публикует событие создания карточки. card — полный KanbanItem с дефолтами.
     *
     * @param array<string, mixed> $card
     */
    public function publishCardCreated(int $boardId, array $card, int $senderId): void
    {
        $this->hub->publish(new Update(
            '/kanban/board/' . $boardId,
            json_encode([
                'type' => 'card_created',
                'card' => $card,
                'senderId' => $senderId,
            ], JSON_THROW_ON_ERROR),
        ));
    }

    /**
     * Публикует событие удаления карточки.
     */
    public function publishCardDeleted(int $boardId, int $cardId, int $senderId): void
    {
        $this->hub->publish(new Update(
            '/kanban/board/' . $boardId,
            json_encode([
                'type' => 'card_deleted',
                'cardId' => $cardId,
                'senderId' => $senderId,
            ], JSON_THROW_ON_ERROR),
        ));
    }

    /**
     * Удобная обёртка: собирает boardId из карточки и публикует патч.
     *
     * @param array<string, mixed> $partial частичный набор полей KanbanItem (без 'id')
     */
    public function publishCardPatch(KanbanCard $card, array $partial, int $senderId): void
    {
        $board = $card->getColumn()->getBoard();
        $boardId = $board->getId();
        if ($boardId === null) {
            return;
        }

        $this->publishCardUpdated(
            $boardId,
            ['id' => $card->getId()] + $partial + ['updatedAt' => $this->formatDate($card->getUpdatedAt())],
            $senderId,
        );
    }

    /**
     * Счётчики подзадач карточки в формате KanbanItem.
     *
     * @return array{checklistTotal: int, checklistDone: int}
     */
    public function buildChecklistCounters(KanbanCard $card): array
    {
        return [
            'checklistTotal' => $card->getSubtasks()->count(),
            'checklistDone' => $card->getSubtasks()->filter(static fn ($s) => $s->isCompleted())->count(),
        ];
    }

    /**
     * Счётчик комментариев карточки в формате KanbanItem
     * (комментарии + вложения с context='chat'), как в formatColumn.
     *
     * @return array{commentsCount: int}
     */
    public function buildCommentsCount(KanbanCard $card): array
    {
        return [
            'commentsCount' => $card->getComments()->count()
                + $card->getAttachments()->filter(static fn ($att) => $att->getContext() === 'chat')->count(),
        ];
    }

    /**
     * Список тегов карточки в формате KanbanItem.
     *
     * @return array{labels: list<array{id: int|null, name: string, color: string}>}
     */
    public function buildLabels(KanbanCard $card): array
    {
        $labels = [];
        foreach ($card->getLabels() as $lbl) {
            $labels[] = ['id' => $lbl->getId(), 'name' => $lbl->getName(), 'color' => $lbl->getColor()->value];
        }

        return ['labels' => $labels];
    }

    /**
     * Список исполнителей карточки в формате KanbanItem.
     *
     * @return array{assignees: list<array{id: int|null, name: string, avatarUrl: string|null}>}
     */
    public function buildAssignees(KanbanCard $card): array
    {
        $assignees = [];
        foreach ($card->getAssignees() as $u) {
            $assignees[] = $this->formatAssignee($u);
        }

        return ['assignees' => $assignees];
    }

    /**
     * @return array{id: int|null, name: string, avatarUrl: string|null}
     */
    public function formatAssignee(User $user): array
    {
        return [
            'id' => $user->getId(),
            'name' => trim($user->getLastname() . ' ' . $user->getFirstname()) ?: (string) $user->getId(),
            'avatarUrl' => $this->userAvatarUrlGenerator->getAvatarUrl($user, UserAvatarUrlGenerator::FILTER_THUMBNAIL),
        ];
    }

    public function formatDate(?\DateTimeInterface $date): ?string
    {
        return $date?->format(\DateTimeInterface::ATOM);
    }
}
