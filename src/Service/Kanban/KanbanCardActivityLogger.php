<?php

declare(strict_types=1);

namespace App\Service\Kanban;

use App\Entity\Kanban\KanbanCard;
use App\Entity\Kanban\KanbanCardActivity;
use App\Entity\User\User;
use App\Enum\Kanban\KanbanCardActivityType;
use App\Enum\Kanban\KanbanCardPriority;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Пишет журнал действий по карточке (история задачи).
 *
 * Запись истории намеренно односторонняя: у KanbanCard нет коллекции activities,
 * поэтому на загрузке доски история не подтягивается. Читается только при
 * открытии конкретной задачи через KanbanCardActivityRepository.
 *
 * Автор действия берётся из Security (текущий аутентифицированный пользователь).
 * Если пользователя нет (например, фоновый процесс) — пишется запись без автора.
 */
class KanbanCardActivityLogger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    /**
     * Базовый метод: создать запись истории и сразу сохранить.
     *
     * old/new — короткие человекочитаемые значения (название столбца, метка
     * приоритета и т.п.). Для событий, где значения не нужны (создание,
     * архивация, изменение описания), оставляем null.
     */
    public function log(
        KanbanCard $card,
        KanbanCardActivityType $type,
        ?string $oldValue = null,
        ?string $newValue = null,
    ): KanbanCardActivity {
        $user = $this->security->getUser();

        $activity = new KanbanCardActivity();
        $activity->setCard($card);
        $activity->setUser($user instanceof User ? $user : null);
        $activity->setType($type);
        $activity->setOldValue($this->trim($oldValue));
        $activity->setNewValue($this->trim($newValue));

        $this->em->persist($activity);
        $this->em->flush();

        return $activity;
    }

    public function logMove(KanbanCard $card, string $fromColumnTitle, string $toColumnTitle): void
    {
        $this->log($card, KanbanCardActivityType::MOVED, $fromColumnTitle, $toColumnTitle);
    }

    public function logRename(KanbanCard $card, string $oldTitle, string $newTitle): void
    {
        $this->log($card, KanbanCardActivityType::RENAMED, $oldTitle, $newTitle);
    }

    /**
     * Изменение описания: храним только факт, без старого/нового текста
     * (описание может быть очень длинным — раздуло бы таблицу истории).
     */
    public function logDescriptionChange(KanbanCard $card): void
    {
        $this->log($card, KanbanCardActivityType::DESCRIPTION_CHANGED);
    }

    public function logPriorityChange(KanbanCard $card, ?KanbanCardPriority $old, ?KanbanCardPriority $new): void
    {
        $this->log(
            $card,
            KanbanCardActivityType::PRIORITY_CHANGED,
            $old?->getLabel() ?? 'не задан',
            $new?->getLabel() ?? 'не задан',
        );
    }

    public function logDueDateChange(KanbanCard $card, ?\DateTimeImmutable $old, ?\DateTimeImmutable $new): void
    {
        $this->log(
            $card,
            KanbanCardActivityType::DUE_DATE_CHANGED,
            $this->formatDate($old),
            $this->formatDate($new),
        );
    }

    public function logColorChange(KanbanCard $card, ?string $old, ?string $new): void
    {
        $this->log(
            $card,
            KanbanCardActivityType::COLOR_CHANGED,
            $old ?? 'без цвета',
            $new ?? 'без цвета',
        );
    }

    public function logAssigneeAdded(KanbanCard $card, string $assigneeName): void
    {
        $this->log($card, KanbanCardActivityType::ASSIGNEE_ADDED, null, $assigneeName);
    }

    public function logAssigneeRemoved(KanbanCard $card, string $assigneeName): void
    {
        $this->log($card, KanbanCardActivityType::ASSIGNEE_REMOVED, $assigneeName, null);
    }

    public function logLabelAdded(KanbanCard $card, string $labelName): void
    {
        $this->log($card, KanbanCardActivityType::LABEL_ADDED, null, $labelName);
    }

    public function logLabelRemoved(KanbanCard $card, string $labelName): void
    {
        $this->log($card, KanbanCardActivityType::LABEL_REMOVED, $labelName, null);
    }

    public function logArchived(KanbanCard $card, bool $archived): void
    {
        $this->log($card, $archived ? KanbanCardActivityType::ARCHIVED : KanbanCardActivityType::RESTORED);
    }

    public function logComment(KanbanCard $card): void
    {
        $this->log($card, KanbanCardActivityType::COMMENT_ADDED);
    }

    public function logAttachmentAdded(KanbanCard $card, string $filename): void
    {
        $this->log($card, KanbanCardActivityType::ATTACHMENT_ADDED, null, $filename);
    }

    public function logAttachmentRemoved(KanbanCard $card, string $filename): void
    {
        $this->log($card, KanbanCardActivityType::ATTACHMENT_REMOVED, $filename, null);
    }

    public function logSubtaskAdded(KanbanCard $card, string $title): void
    {
        $this->log($card, KanbanCardActivityType::SUBTASK_ADDED, null, $title);
    }

    public function logSubtaskCompleted(KanbanCard $card, string $title, bool $completed): void
    {
        $this->log(
            $card,
            $completed ? KanbanCardActivityType::SUBTASK_COMPLETED : KanbanCardActivityType::SUBTASK_REOPENED,
            null,
            $title,
        );
    }

    public function logSubtaskRemoved(KanbanCard $card, string $title): void
    {
        $this->log($card, KanbanCardActivityType::SUBTASK_REMOVED, $title, null);
    }

    public function logSubtaskAssigned(KanbanCard $card, string $subtaskTitle, string $assigneeName): void
    {
        $this->log(
            $card,
            KanbanCardActivityType::SUBTASK_ASSIGNED,
            null,
            $assigneeName . ' (подзадача: ' . $subtaskTitle . ')',
        );
    }

    public function logSubtaskUnassigned(KanbanCard $card, string $subtaskTitle, string $assigneeName): void
    {
        $this->log(
            $card,
            KanbanCardActivityType::SUBTASK_UNASSIGNED,
            $assigneeName . ' (подзадача: ' . $subtaskTitle . ')',
            null,
        );
    }

    private function formatDate(?\DateTimeImmutable $date): string
    {
        return $date?->format('d.m.Y H:i') ?? 'не задан';
    }

    /**
     * Подрезаем значение под лимит колонки (1000) с запасом.
     */
    private function trim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return mb_substr($value, 0, 1000);
    }
}
