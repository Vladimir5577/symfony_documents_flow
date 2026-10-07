<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Entity\Purchase\PurchaseApprovalTask;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseRoleCode;
use App\Enum\Purchase\PurchaseTaskAssignment;
use App\Service\Notification\NotificationPublisher;

/**
 * Уведомления модуля закупок: кто получатель и как звучит заголовок.
 *
 * Отправкой занимается общий NotificationPublisher — здесь только знание
 * модуля. Раньше заголовки собирал switch на стороне сервиса нотификаций, в
 * другом репозитории и на другом языке: добавить тип события значило править
 * и деплоить чужой сервис. Теперь текст живёт рядом с бизнес-логикой, которая
 * его и знает.
 *
 * Все события заявки — автору и назначенному профильному заму.
 * Бухгалтерия, юристы, директор, финдиректор и отдел закупок слышат только
 * «ждёт вашего решения», когда активна их задача. Админ — только если он
 * сам в одной из этих ролей.
 */
final class PurchaseNotificationPublisher
{
    private const MODULE = 'purchase';

    public function __construct(
        private readonly NotificationPublisher $publisher,
        private readonly PurchaseRoster $roster,
    ) {}

    /** Подана (или повторно подана) на рассмотрение — всем, кому она видна. */
    public function notifySubmitted(PurchaseRequest $request, User $actor, bool $resubmitted): void
    {
        $title = $resubmitted
            ? sprintf('Заявка на закупку «%s» подана повторно', $this->titleOf($request))
            : sprintf('Новая заявка на закупку «%s» на рассмотрении', $this->titleOf($request));

        $this->publish('submitted', $request, $actor, $this->viewersOf($request), $title, 'Новая заявка на закупку');
    }

    /**
     * Активировался этап маршрута — зовём всех, кто в нём стоит.
     *
     * Ролевые задачи разворачиваем в носителей роли. Это критично: согласантом
     * может быть человек без закупочных ролей, и колокольчик для него —
     * единственная точка входа в заявку.
     */
    public function notifyStageActivated(PurchaseRequest $request, User $actor): void
    {
        $recipients = [];
        foreach ($request->getActiveTasks() as $task) {
            foreach ($this->addresseesOf($task, $request) as $user) {
                $recipients[$user->getId()] = $user;
            }
        }

        // Себе уведомление не шлём: закрыл задачу и тут же стоишь на следующей —
        // бывает у отдела закупок, они в маршруте дважды.
        unset($recipients[$actor->getId()]);

        if ($recipients === []) {
            return;
        }

        $this->publish(
            'stage_activated', $request, $actor, array_values($recipients),
            sprintf('Закупка «%s» ждёт вашего решения', $this->titleOf($request)),
            'Требуется решение',
        );
    }

    /**
     * Разбирающий выбрал согласантов — им самим.
     *
     * Отдельно от stage_activated: подписывать они будут ещё долго не сейчас, а
     * ответственными становятся сразу, и заявку надо начинать отслеживать с этого
     * момента, а не с момента, когда до них дойдёт очередь.
     *
     * @param list<User> $approvers
     */
    public function notifyApproversAssigned(PurchaseRequest $request, User $actor, array $approvers): void
    {
        if ($approvers === []) {
            return;
        }

        $this->publish(
            'approvers_assigned', $request, $actor, $approvers,
            sprintf('Вы ответственный по закупке «%s»', $this->titleOf($request)),
            'Назначение по закупке',
        );
    }

    /** Забракованы документы — всем, кому заявка видна. */
    public function notifyReturnedToDepartment(PurchaseRequest $request, User $actor, string $comment): void
    {
        $this->publish(
            'returned_to_department', $request, $actor, $this->viewersOf($request),
            sprintf('Закупка «%s» вернулась в отдел закупок', $this->titleOf($request)),
            'Возврат в отдел закупок',
            $comment !== '' ? $comment : null,
        );
    }

    /** Возвращена на доработку — всем, кому заявка видна. */
    public function notifyRejected(PurchaseRequest $request, User $actor, string $comment): void
    {
        $this->publish(
            'rejected', $request, $actor, $this->viewersOf($request),
            sprintf('Заявка на закупку «%s» возвращена на доработку', $this->titleOf($request)),
            'Возврат на доработку',
            $comment !== '' ? $comment : null,
        );
    }

    /**
     * Сдвиг по маршруту (подпись, отзыв) — зрителям заявки.
     * Адресное «ждёт вашего решения» шлёт notifyStageActivated отдельно.
     */
    public function notifyChanged(PurchaseRequest $request, User $actor, string $title, string $typeLabel = 'Заявка обновлена'): void
    {
        $this->publish('changed', $request, $actor, $this->viewersOf($request), $title, $typeLabel);
    }

    /** Отменена — всем, кому заявка видна. */
    public function notifyCancelled(PurchaseRequest $request, User $actor, ?string $comment): void
    {
        $this->publish(
            'cancelled', $request, $actor, $this->viewersOf($request),
            sprintf('Заявка на закупку «%s» отменена', $this->titleOf($request)),
            'Заявка отменена',
            $comment !== null && $comment !== '' ? $comment : null,
        );
    }

    /**
     * Этап оплаты закрыт — автору и профильному заму: деньги ушли, дальше ждать поставку.
     */
    public function notifyPaymentConfirmed(PurchaseRequest $request, User $actor): void
    {
        $this->publish(
            'payment_confirmed', $request, $actor, $this->viewersOf($request),
            sprintf('Оплата по вашей закупке «%s» подтверждена — ожидайте товар или услугу', $this->titleOf($request)),
            'Оплата подтверждена',
        );
    }

    /**
     * Текст договора поменялся после согласования — автору и профильному заму.
     * Согласовавшие роли отдельным письмом не зовутся: действия от них нет.
     *
     * @param list<User> $approvers
     */
    public function notifyContractChanged(PurchaseRequest $request, User $actor, array $approvers, string $fileName): void
    {
        if ($approvers === []) {
            return;
        }

        $this->publish(
            'contract_changed', $request, $actor, $this->viewersOf($request),
            sprintf('Договор по закупке «%s» изменён после вашего согласования', $this->titleOf($request)),
            'Договор изменён',
            $fileName !== '' ? $fileName : null,
        );
    }

    /** Новый комментарий — всем, кому заявка видна. */
    public function notifyCommentAdded(PurchaseRequest $request, User $actor): void
    {
        $this->publish(
            'comment_added', $request, $actor, $this->viewersOf($request),
            sprintf('%s оставил(а) комментарий к закупке «%s»', $this->nameOf($actor), $this->titleOf($request)),
            'Комментарий к закупке',
        );
    }

    /**
     * @param list<User> $recipients
     */
    private function publish(
        string $event,
        PurchaseRequest $request,
        User $actor,
        array $recipients,
        string $title,
        string $typeLabel,
        ?string $message = null,
    ): void {
        $this->publisher->publish(
            module: self::MODULE,
            event: $event,
            recipients: $recipients,
            title: $title,
            link: '/purchases/' . $request->getId(),
            actor: $actor,
            typeLabel: $typeLabel,
            message: $message,
        );
    }

    private function titleOf(PurchaseRequest $request): string
    {
        return (string) $request->getTitle();
    }

    private function nameOf(User $user): string
    {
        $name = trim(($user->getLastname() ?? '') . ' ' . ($user->getFirstname() ?? ''));

        return $name !== '' ? $name : (string) $user->getLogin();
    }

    /**
     * Автор и назначенные на эту заявку профильные замы.
     *
     * @return list<User>
     */
    private function viewersOf(PurchaseRequest $request): array
    {
        $recipients = [];
        $add = static function (?User $user) use (&$recipients): void {
            if ($user !== null && $user->getId() !== null) {
                $recipients[$user->getId()] = $user;
            }
        };

        $add($request->getCreatedBy());

        foreach ($request->getStages() as $stage) {
            if ($stage->getCandidateRoleCode() !== PurchaseRoleCode::PROFILE_DEPUTY) {
                continue;
            }
            foreach ($stage->getTasks() as $task) {
                $add($task->getAssigneeUser());
            }
        }

        return array_values($recipients);
    }

    /**
     * Кому адресована задача: конкретному человеку, автору заявки или носителям
     * роли.
     *
     * @return list<User>
     */
    private function addresseesOf(PurchaseApprovalTask $task, PurchaseRequest $request): array
    {
        if ($task->getAssignmentType() === PurchaseTaskAssignment::AUTHOR) {
            $author = $request->getCreatedBy();

            return $author !== null ? [$author] : [];
        }

        $user = $task->getAssigneeUser();
        if ($user !== null) {
            return [$user];
        }

        return $this->roster->usersOfRole($task->getRoleCode());
    }
}
