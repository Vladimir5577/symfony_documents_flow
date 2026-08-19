<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Entity\Purchase\PurchaseRequest;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseCapability;
use App\Enum\Purchase\PurchaseStepPurpose;
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
        private readonly PurchaseRoster $roster,
    ) {}

    /** Подана (или повторно подана) на рассмотрение — отделу закупок. */
    public function notifySubmitted(PurchaseRequest $request, User $actor, bool $resubmitted): void
    {
        $title = $resubmitted
            ? sprintf('Заявка на закупку «%s» подана повторно', $this->titleOf($request))
            : sprintf('Новая заявка на закупку «%s» на рассмотрении', $this->titleOf($request));

        $this->publish('submitted', $request, $actor, $this->moduleStaff(), $title, 'Новая заявка на закупку');
    }

    /**
     * Активировалась позиция маршрута — зовём всех, кто на ней стоит.
     *
     * Ролевые шаги разворачиваем в носителей роли. Это критично: согласантом
     * может быть человек без закупочных ролей, и колокольчик для него —
     * единственная точка входа в заявку.
     */
    public function notifyStepActivated(PurchaseRequest $request, User $actor): void
    {
        $recipients = [];
        foreach ($request->getActiveSteps() as $step) {
            $user = $step->getApproverUser();
            if ($user !== null) {
                $recipients[$user->getId()] = $user;
                continue;
            }
            foreach ($this->roster->usersOfRole($step->getRoleCode()) as $holder) {
                $recipients[$holder->getId()] = $holder;
            }
        }

        // Себе уведомление не шлём: закрыл шаг и тут же на нём стоишь — бывает
        // у отдела закупок, они в маршруте дважды.
        unset($recipients[$actor->getId()]);

        if ($recipients === []) {
            return;
        }

        $this->publish(
            'step_activated', $request, $actor, array_values($recipients),
            sprintf('Закупка «%s» ждёт вашего согласования', $this->titleOf($request)),
            'Требуется согласование',
        );
    }

    /**
     * Директор назначил профильных замов — им самим.
     *
     * Отдельно от step_activated: подписывать они будут ещё долго не сейчас,
     * а ответственными становятся сразу, и заявку надо начинать отслеживать
     * с этого момента, а не с момента, когда до них дойдёт очередь.
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

    /** Забракованы документы — тем, кто вёл ресёрч: переделывать им. */
    public function notifyReturnedToDepartment(PurchaseRequest $request, User $actor, string $comment): void
    {
        $recipients = $this->sourcingHolders($request);

        $this->publish(
            'returned_to_department', $request, $actor, $recipients !== [] ? $recipients : $this->moduleStaff(),
            sprintf('Закупка «%s» вернулась в отдел закупок', $this->titleOf($request)),
            'Возврат в отдел закупок',
            $comment !== '' ? $comment : null,
        );
    }

    /** Согласована — менеджерам департамента и тем, кто её будет исполнять. */
    public function notifyApproved(PurchaseRequest $request, User $actor): void
    {
        $recipients = array_merge($this->departmentManagers($request), $this->executionStaff());

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
            $this->supervisors(),
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

    /**
     * Кому уходят уведомления «модулю», а не шагу. Спрашиваем полномочие, а не
     * роль: набор ролей ещё будет меняться, и зашивать здесь «Отдел закупок»
     * значило бы, что переехавшая функция тихо перестанет получать письма.
     *
     * @return list<User>
     */
    private function moduleStaff(): array
    {
        return $this->roster->usersWith(PurchaseCapability::MANAGE_DICTIONARIES);
    }

    /** @return list<User> */
    private function supervisors(): array
    {
        return $this->roster->usersWith(PurchaseCapability::SUPERVISE);
    }

    /** @return list<User> */
    private function executionStaff(): array
    {
        return $this->roster->usersWith(PurchaseCapability::RUN_EXECUTION);
    }

    /**
     * Носители роли того шага, где заявка делает ресёрч. Возврат документов
     * адресуется им, а не «отделу закупок» вообще: в маршруте с двумя закупками
     * переделывать будет тот, кто этот пакет и собирал.
     *
     * @return list<User>
     */
    private function sourcingHolders(PurchaseRequest $request): array
    {
        foreach ($request->getSteps() as $step) {
            if ($step->getPurpose() !== PurchaseStepPurpose::SOURCING) {
                continue;
            }

            $user = $step->getApproverUser();

            return $user !== null ? [$user] : $this->roster->usersOfRole($step->getRoleCode());
        }

        return [];
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
