<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Entity\Purchase\PurchaseRequest;
use App\Entity\User\User;
use App\Enum\User\UserRole;
use App\Repository\User\UserRepository;
use App\Service\Notification\NotificationPublisher;

/**
 * Уведомления модуля закупок: кто получатель и как звучит заголовок.
 *
 * Отправкой занимается общий NotificationPublisher — здесь только знание
 * модуля. Раньше заголовки собирал switch на стороне сервиса нотификаций, в
 * другом репозитории и на другом языке: добавить тип события значило править
 * и деплоить чужой сервис. Теперь текст живёт рядом с бизнес-логикой, которая
 * его и знает.
 */
final class PurchaseNotificationPublisher
{
    private const MODULE = 'purchase';

    public function __construct(
        private readonly NotificationPublisher $publisher,
        private readonly UserRepository $userRepository,
    ) {}

    /** Подана (или повторно подана) на рассмотрение — отделу закупок. */
    public function notifySubmitted(PurchaseRequest $request, User $actor, bool $resubmitted): void
    {
        $title = $resubmitted
            ? sprintf('Заявка на закупку «%s» подана повторно', $this->titleOf($request))
            : sprintf('Новая заявка на закупку «%s» на рассмотрении', $this->titleOf($request));

        $this->publish('submitted', $request, $actor, $this->purchaseDepartment(), $title, 'Новая заявка на закупку');
    }

    /** Отдел закупок направил заявку директору. */
    public function notifySentToDirector(PurchaseRequest $request, User $actor): void
    {
        $this->publish(
            'sent_to_director', $request, $actor, $this->directors(),
            sprintf('Заявка на закупку «%s» направлена на согласование', $this->titleOf($request)),
            'Заявка на согласовании',
        );
    }

    /** Приглашение согласанта — приглашённому. */
    public function notifyApproverInvited(PurchaseRequest $request, User $actor, User $invited): void
    {
        $this->publish(
            'approver_invited', $request, $actor, [$invited],
            sprintf('Вас пригласили согласовать закупку «%s»', $this->titleOf($request)),
            'Приглашение согласовать',
        );
    }

    /** Согласант подтвердил — пригласившему (или отделу закупок, если пригласивший удалён). */
    public function notifyApproverConfirmed(PurchaseRequest $request, User $approver, ?User $invitedBy): void
    {
        $recipients = $invitedBy !== null ? [$invitedBy] : $this->purchaseDepartment();

        $this->publish(
            'approver_confirmed', $request, $approver, $recipients,
            sprintf('%s подтвердил(а) согласование закупки «%s»', $this->nameOf($approver), $this->titleOf($request)),
            'Согласование подтверждено',
        );
    }

    /** Согласована — менеджерам департамента и отделу закупок. */
    public function notifyApproved(PurchaseRequest $request, User $actor): void
    {
        $recipients = array_merge($this->departmentManagers($request), $this->purchaseDepartment());

        $this->publish(
            'approved', $request, $actor, $recipients,
            sprintf('Заявка на закупку «%s» согласована', $this->titleOf($request)),
            'Закупка согласована',
        );
    }

    /** Возвращена на доработку — менеджерам департамента. */
    public function notifyRejected(PurchaseRequest $request, User $actor, string $comment): void
    {
        $this->publish(
            'rejected', $request, $actor, $this->departmentManagers($request),
            sprintf('Заявка на закупку «%s» возвращена на доработку', $this->titleOf($request)),
            'Возврат на доработку',
            $comment !== '' ? $comment : null,
        );
    }

    /** Продвижение по конвейеру исполнения — менеджерам департамента. */
    public function notifyStatusChanged(PurchaseRequest $request, User $actor): void
    {
        $this->publish(
            'status_changed', $request, $actor, $this->departmentManagers($request),
            sprintf('Заявка на закупку «%s»: %s', $this->titleOf($request), $request->getStatus()->getLabel()),
            'Статус закупки изменён',
        );
    }

    /** Доставлено, пора принимать — менеджерам департамента. */
    public function notifyDelivered(PurchaseRequest $request, User $actor): void
    {
        $this->publish(
            'delivered', $request, $actor, $this->departmentManagers($request),
            sprintf('Закупка «%s» доставлена — подтвердите получение', $this->titleOf($request)),
            'Закупка доставлена',
        );
    }

    /** Департамент подтвердил получение — исполнителю. */
    public function notifyConfirmed(PurchaseRequest $request, User $actor): void
    {
        $this->publish(
            'confirmed', $request, $actor, array_filter([$request->getExecutor()]),
            sprintf('Получение закупки «%s» подтверждено', $this->titleOf($request)),
            'Получение подтверждено',
        );
    }

    /** Отменена — всем участникам процесса. */
    public function notifyCancelled(PurchaseRequest $request, User $actor, ?string $comment): void
    {
        $recipients = array_merge(
            $this->departmentManagers($request),
            $this->directors(),
            array_filter([$request->getExecutor()]),
        );

        $this->publish(
            'cancelled', $request, $actor, $recipients,
            sprintf('Заявка на закупку «%s» отменена', $this->titleOf($request)),
            'Заявка отменена',
            $comment !== null && $comment !== '' ? $comment : null,
        );
    }

    /** Новый комментарий — автору заявки и исполнителю. */
    public function notifyCommentAdded(PurchaseRequest $request, User $actor): void
    {
        $recipients = array_filter([$request->getCreatedBy(), $request->getExecutor()]);

        $this->publish(
            'comment_added', $request, $actor, $recipients,
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

    /** @return list<User> */
    private function directors(): array
    {
        return $this->userRepository->findByRoleName(UserRole::ROLE_PURCHASE_DIRECTOR->value);
    }

    /** @return list<User> */
    private function purchaseDepartment(): array
    {
        return $this->userRepository->findByRoleName(UserRole::ROLE_PURCHASE_DEPARTMENT->value);
    }

    /** @return list<User> */
    private function departmentManagers(PurchaseRequest $request): array
    {
        $organization = $request->getOrganization();
        if ($organization === null) {
            return [];
        }

        return $this->userRepository->findByRoleName(UserRole::ROLE_MANAGER->value, $organization);
    }
}
