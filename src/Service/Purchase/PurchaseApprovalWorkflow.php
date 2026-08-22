<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseApprovalStage;
use App\Entity\Purchase\PurchaseApprovalTask;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRouteTemplate;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseHistoryAction;
use App\Enum\Purchase\PurchasePriority;
use App\Enum\Purchase\PurchaseStagePurpose;
use App\Enum\Purchase\PurchaseStageStatus;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\Purchase\PurchaseTaskDecision;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;

/**
 * Движение заявки по маршруту — единственное место, где меняются статусы этапов,
 * решения задач и статус заявки.
 *
 * Указатель маршрута хранится: активный этап помечен статусом, а не выводится из
 * решений. Раньше «где стоит заявка» считалось на каждый вызов как минимальная
 * незакрытая позиция, и переход к следующему этапу был решением, принятым в памяти
 * процесса. Двое согласантов параллельного этапа, нажавшие одновременно, каждый
 * видел только своё согласие — и этап не закрывался ни у одного из них. Теперь
 * переход это запись, а запись идёт под оптимистичной блокировкой: второй получает
 * отказ и повторяет с актуальными данными.
 *
 * Исполнение — такие же этапы, как согласование. Оплата, поставка и закрытие
 * больше не отдельная цепочка статусов со своими правилами «кому можно»: они
 * задачи маршрута, а статус заявки — проекция того, какой отрезок пройден.
 * Поэтому «доставку подтверждает склад, а не заявитель» стало правкой в админке.
 *
 * Права («кто может») проверяет PurchaseAccess: у него есть роли и полномочия.
 * Здесь — только корректность («можно ли сейчас»), запись в историю и уведомление.
 */
final class PurchaseApprovalWorkflow
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PurchaseNotificationPublisher $notifier,
        private readonly ApprovalRouteResolver $resolver,
        private readonly ApprovalRouteBuilder $builder,
        private readonly PurchaseHistoryLogger $history,
        private readonly PurchaseRequestEditor $editor,
    ) {
    }

    /** Запись о создании заявки (from = NULL). Без flush — вызывается при создании. */
    public function logCreated(PurchaseRequest $request, User $actor): void
    {
        $this->history->logTransition(
            $request,
            $actor,
            null,
            PurchaseStatus::DRAFT,
            PurchaseHistoryAction::CREATED,
        );
    }

    /**
     * DRAFT | REJECTED → ON_APPROVAL: собираем снимок маршрута и запускаем его.
     *
     * Повторная подача собирает снимок заново и по нынешней заготовке: состав и
     * сумма к этому времени изменились, а регламент могли поправить — согласовывать
     * надо то, что есть, по тому, как сейчас положено.
     */
    public function submit(PurchaseRequest $request, User $actor): void
    {
        $from = $request->getStatus();
        if (!$from->isEditable()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_STATUS);
        }
        if ($request->getItems()->isEmpty()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_ITEMS_REQUIRED);
        }

        $this->builder->build($request, $this->resolver->resolve($request));

        $request->setStatus(PurchaseStatus::ON_APPROVAL);
        $this->history->logTransition(
            $request,
            $actor,
            $from,
            PurchaseStatus::ON_APPROVAL,
            PurchaseHistoryAction::SUBMITTED,
            $request->getAppliedRouteTemplateName(),
        );

        $this->advance($request, $actor);
        $this->save($request);

        $this->notifier->notifySubmitted($request, $actor, resubmitted: $from === PurchaseStatus::REJECTED);
        $this->notifier->notifyStageActivated($request, $actor);
    }

    /**
     * Закрыть свою задачу согласием.
     *
     * Пока в этапе остались незакрытые задачи, указатель не двигается: параллельные
     * подписи ложатся в любом порядке, этап уходит, когда закрыты все.
     */
    public function approveTask(
        PurchaseRequest $request,
        PurchaseApprovalTask $task,
        User $actor,
        ?string $comment = null,
    ): void {
        $stage = $this->assertActiveTask($request, $task);

        // Требование файла живёт на задаче, а не в конвейере: у быстрого маршрута
        // задачи «договор» нет, и требовать с него договор не за что. Так же и УПД
        // при закрытии — это файл задачи закрытия, а не условие перехода.
        $requiredFile = $task->getRequiresFileType();
        if ($requiredFile !== null && !$request->hasFileOfType($requiredFile)) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_TASK_FILE_REQUIRED);
        }

        $task->decide(PurchaseTaskDecision::APPROVED, $actor, $comment);

        // Исполнитель — тот, кто закрыл ресёрч: он искал поставщика и готовил
        // документы. Не тот, кто первым коснулся исполнения: платит финдиректор,
        // и исполнителем становился бы он.
        if ($request->getExecutor() === null && $stage->getPurpose() === PurchaseStagePurpose::SOURCING) {
            $request->setExecutor($actor);
        }

        $this->history->log(
            $request,
            $actor,
            PurchaseHistoryAction::TASK_APPROVED,
            $this->history->taskComment($task, $comment),
        );

        if (!$stage->isSatisfied()) {
            $this->save($request);

            return;
        }

        $this->closeStage($request, $stage, $actor);
        $this->save($request);
        $this->announce($request, $actor);
    }

    /**
     * Вернуть заявку автору со своей задачи. Комментарий обязателен.
     *
     * Не с любого этапа: на исполнении товар уже оплачен, и «вернуть автору»
     * означало бы не решение по заявке, а потерянные деньги. Разрешает ли этап
     * отказ — свойство этапа, скопированное из заготовки.
     */
    public function rejectTask(
        PurchaseRequest $request,
        PurchaseApprovalTask $task,
        User $actor,
        string $comment,
    ): void {
        $stage = $this->assertActiveTask($request, $task);

        if (trim($comment) === '') {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_COMMENT_REQUIRED);
        }
        if (!$stage->allowsReject()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_REJECT_NOT_ALLOWED);
        }

        $from = $request->getStatus();
        $task->decide(PurchaseTaskDecision::REJECTED, $actor, $comment);
        $request->setStatus(PurchaseStatus::REJECTED);

        $this->history->logTransition(
            $request,
            $actor,
            $from,
            PurchaseStatus::REJECTED,
            PurchaseHistoryAction::TASK_REJECTED,
            $this->history->taskComment($task, $comment),
        );
        $this->save($request);

        $this->notifier->notifyRejected($request, $actor, $comment);
    }

    /**
     * Вернуть заявку в отдел закупок со своей задачи. Комментарий обязателен.
     *
     * Бухгалтерия и юристы бракуют не заявку, а пакет документов: возвращать её
     * автору незачем — он этих документов не готовил и починить их не может.
     * Поэтому заявка остаётся на согласовании и откатывается на этап ресёрча, а
     * решения, успевшие лечь после него, сбрасываются: пакет будет другой.
     *
     * В маршруте без ресёрча возвращать некуда, и кнопки этой не будет: такой
     * маршрут законен, просто в нём никто не готовит документы.
     */
    public function returnToSourcing(
        PurchaseRequest $request,
        PurchaseApprovalTask $task,
        User $actor,
        string $comment,
    ): void {
        $stage = $this->assertActiveTask($request, $task);

        if (trim($comment) === '') {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_COMMENT_REQUIRED);
        }

        $sourcing = $request->findStageByPurpose(PurchaseStagePurpose::SOURCING);
        if ($sourcing === null || $sourcing->getPosition() >= $stage->getPosition()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_TASK_NOT_ACTIVE);
        }

        $burned = $this->rewindTo($request, $sourcing);

        $this->history->log(
            $request,
            $actor,
            PurchaseHistoryAction::RETURNED_TO_DEPARTMENT,
            $this->history->taskComment($task, $comment)
                . ($burned === [] ? '' : '. Сброшены согласования: ' . implode(', ', $burned)),
        );
        $this->save($request);

        $this->notifier->notifyReturnedToDepartment($request, $actor, $comment);
    }

    /**
     * Снять свою подпись — персональный откат для того, кто её поставил.
     *
     * Подпись необратима по замыслу: этап закрылся, следующим ушли уведомления.
     * Но когда в этапе подписант один, он закрывается сразу, и «окна на
     * передумать» не остаётся вовсе — а ошибиться тогглом легко. Отзыв маршрута
     * это лечит, но требует второго человека.
     *
     * Поэтому здесь то же, что делает отзыв, но в границах одного человека:
     * сбрасываем его задачу и всё, что успело решиться после, и возвращаем
     * указатель на его этап.
     *
     * Подписи на исполнении не отзываются: там уже прошли деньги, и «снять
     * подпись об оплате» — это не откат решения, а расхождение с банком.
     */
    public function revokeTask(PurchaseRequest $request, PurchaseApprovalTask $task, User $actor): void
    {
        $from = $request->getStatus();

        // APPROVED тоже допустим: если подписант был последним в согласовании,
        // маршрут уже дошёл до конца — но пока исполнение не началось, откатить можно.
        if (!in_array($from, [PurchaseStatus::ON_APPROVAL, PurchaseStatus::APPROVED], true)) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_STATUS);
        }

        $stage = $task->getStage();
        if ($stage === null || $stage->getPurchaseRequest()?->getId() !== $request->getId()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_TASK_NOT_FOUND);
        }
        // Именно СВОЮ: чужую подпись снимает только отзыв маршрута отделом закупок.
        if ($task->getDecision() !== PurchaseTaskDecision::APPROVED
            || $task->getDecidedBy()?->getId() !== $actor->getId()
        ) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_TASK_NOT_REVOKABLE);
        }
        if ($stage->getPurpose()->isExecution()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_TASK_NOT_REVOKABLE);
        }

        $burned = $this->rewindTo($request, $stage, $task);

        $this->history->logTransition(
            $request,
            $actor,
            $from,
            $request->getStatus(),
            PurchaseHistoryAction::TASK_REVOKED,
            $burned === []
                ? 'Согласование снято автором подписи'
                : 'Согласование снято автором подписи. Сброшены согласования после него: '
                    . implode(', ', $burned),
        );
        $this->save($request);

        $this->notifier->notifyStageActivated($request, $actor);
    }

    /**
     * Решение разбирающего: правки состава, выбор согласантов и отправка дальше.
     *
     * Всё одной транзакцией намеренно. Разнеси правку состава и вердикт по двум
     * запросам — и закрытая на полпути вкладка оставит заявку с урезанным составом,
     * но без решения: у согласанта окажется не то, что разбирающий согласовал.
     *
     * Согласанты приходят списками по этапам: динамических этапов в маршруте может
     * быть несколько, и «замы» с «службой безопасности» выбираются каждый на свой.
     * Подписывать они будут по готовым документам, но ответственными становятся
     * сразу — заявка появляется у них в списке, и им уходит уведомление.
     *
     * Пустой список — законное решение: значит, на этом этапе согласовывать некому.
     * Этап так и останется ждать назначения, а указатель, дойдя до него, проедет
     * мимо: см. advance().
     *
     * Что выбранные входят в пул этапа, проверяет вызывающий: пул — состав людей,
     * и спрашивать его надо у ростера.
     *
     * @param array<int, array{included: bool, quantity: string|null}> $itemEdits ключ — id позиции
     * @param array<int, list<User>> $assignments ключ — id этапа
     */
    public function triage(
        PurchaseRequest $request,
        PurchaseApprovalTask $task,
        User $actor,
        array $itemEdits,
        array $assignments,
    ): void {
        $stage = $this->assertActiveTask($request, $task);

        if ($stage->getPurpose() !== PurchaseStagePurpose::TRIAGE) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_TASK_NOT_ACTIVE);
        }

        $changes = $this->editor->applyItemEdits($request, $itemEdits);
        if ($changes !== []) {
            $this->history->log(
                $request,
                $actor,
                PurchaseHistoryAction::ITEMS_EDITED,
                'Состав правил разбирающий: ' . implode('; ', $changes),
            );
        }

        $assigned = [];
        foreach ($assignments as $stageId => $users) {
            $target = $this->assertAssignable($request, $stageId);
            foreach ($this->builder->assign($target, $users, $actor) as $user) {
                $assigned[] = $user;
            }
            if (!$target->getTasks()->isEmpty()) {
                $target->setStatus(PurchaseStageStatus::PENDING);
            }
        }

        if ($assigned !== []) {
            $this->history->log(
                $request,
                $actor,
                PurchaseHistoryAction::APPROVERS_ASSIGNED,
                'Ответственные: ' . implode(', ', array_map(
                    static fn (User $user): string => PurchaseHistoryLogger::nameOf($user),
                    $assigned,
                )),
            );
        }

        // Решение разбирающего закрывает его задачу: указатель уедет дальше.
        $task->decide(PurchaseTaskDecision::APPROVED, $actor);
        $this->history->log(
            $request,
            $actor,
            PurchaseHistoryAction::TASK_APPROVED,
            $this->history->taskComment($task, null),
        );

        if ($stage->isSatisfied()) {
            $this->closeStage($request, $stage, $actor);
        }

        $this->save($request);

        if ($assigned !== []) {
            $this->notifier->notifyApproversAssigned($request, $actor, $assigned);
        }
        $this->announce($request, $actor);
    }

    /**
     * Сменить маршрут заявки и собрать снимок заново.
     *
     * Только пока активен разбор: дальше в маршруте уже лежат чужие решения, и
     * пересборка сожгла бы их — согласанты подписывали бы не тот маршрут, по
     * которому заявка поедет.
     *
     * Заявку это не двигает: после смены разбирающий остаётся на разборе нового
     * маршрута и отправляет её обычной кнопкой. Иначе «сменить маршрут» тихо
     * делало бы два дела вместо одного, и отменить второе было бы нечем.
     */
    public function changeRoute(
        PurchaseRequest $request,
        PurchaseApprovalTask $task,
        PurchaseRouteTemplate $template,
        User $actor,
    ): void {
        $stage = $this->assertActiveTask($request, $task);

        if ($stage->getPurpose() !== PurchaseStagePurpose::TRIAGE) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_ROUTE_NOT_CHANGEABLE);
        }
        if (!$this->resolver->isUsable($template, $request)) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_ROUTE_NOT_CONFIGURED);
        }
        // Новый маршрут без разбора оставил бы разбирающего без задачи посреди
        // его же действия: заявка повисла бы между «разобрал» и «отправил».
        if ($template->findTriageStage() === null) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_ROUTE_NOT_CHANGEABLE);
        }

        $was = $request->getAppliedRouteTemplateName();

        $request->setRouteTemplate($template);
        $this->builder->build($request, $template);
        $this->advance($request, $actor);

        $this->history->log(
            $request,
            $actor,
            PurchaseHistoryAction::ROUTE_CHANGED,
            sprintf('%s → %s', $was ?? 'маршрут по умолчанию', (string) $template->getName()),
        );
        $this->save($request);
    }

    /** Отмена из любого нефинального статуса. */
    public function cancel(PurchaseRequest $request, User $actor, ?string $comment): void
    {
        if ($request->getStatus()->isFinal()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_STATUS);
        }

        $from = $request->getStatus();
        $request->setStatus(PurchaseStatus::CANCELLED);
        $this->history->logTransition(
            $request,
            $actor,
            $from,
            PurchaseStatus::CANCELLED,
            PurchaseHistoryAction::CANCELLED,
            $comment,
        );
        $this->save($request);

        $this->notifier->notifyCancelled($request, $actor, $comment);
    }

    /** Смена приоритета. */
    public function setPriority(PurchaseRequest $request, User $actor, PurchasePriority $priority): void
    {
        if ($request->getStatus()->isFinal()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_STATUS);
        }
        if ($request->getPriority() === $priority) {
            return;
        }

        $request->setPriority($priority);
        $this->history->log($request, $actor, PurchaseHistoryAction::PRIORITY_CHANGED, $priority->getLabel());
        $this->save($request);
    }

    /**
     * Этап этой заявки, на который можно назначать людей.
     *
     * @throws PurchaseTransitionException
     */
    private function assertAssignable(PurchaseRequest $request, int $stageId): PurchaseApprovalStage
    {
        foreach ($request->getStages() as $stage) {
            if ($stage->getId() !== $stageId) {
                continue;
            }
            if (!$stage->isAwaitingAssignment() || !$stage->isDynamic()) {
                throw new PurchaseTransitionException(SpaApiError::PURCHASE_TASK_NOT_ACTIVE);
            }

            return $stage;
        }

        throw new PurchaseTransitionException(SpaApiError::PURCHASE_TASK_NOT_FOUND);
    }

    /** Закрыть этап и подвинуть указатель. */
    private function closeStage(PurchaseRequest $request, PurchaseApprovalStage $stage, User $actor): void
    {
        $stage->setStatus(PurchaseStageStatus::COMPLETED);

        $reached = PurchaseStatus::afterStage($stage->getPurpose());
        if ($reached !== null) {
            $this->setStatus($request, $actor, $reached);
        }

        $this->advance($request, $actor);
    }

    /**
     * Поставить указатель на первый этап, который есть кому закрывать.
     *
     * Динамический этап, на который никого не выбрали, проезжаем: пустой список
     * согласантов — это решение разбирающего «здесь согласовывать некому», а не
     * повод остановить заявку в ожидании тех, кого не будет.
     *
     * Пустой нединамический этап — ошибка настройки, и здесь она становится
     * отказом. Такого этапа не пропустит редактор заготовок, но проехать
     * согласование молча хуже, чем остановиться с внятной ошибкой.
     */
    private function advance(PurchaseRequest $request, User $actor): void
    {
        while (true) {
            $stage = $request->findNextOpenStage();

            if ($stage === null) {
                // Этапов не осталось: согласование пройдено, а исполнения в
                // маршруте нет. Это законная настройка, а не зависшая заявка —
                // регламент кончился подписями.
                $this->closeApprovalPart($request, $actor);

                return;
            }

            if (!$stage->getTasks()->isEmpty()) {
                // Дошли до исполнения — значит согласование позади. Иначе оплату
                // ждала бы заявка, по статусу всё ещё «на согласовании».
                if ($stage->getPurpose()->isExecution()) {
                    $this->closeApprovalPart($request, $actor);
                }
                $stage->setStatus(PurchaseStageStatus::ACTIVE);

                return;
            }

            if (!$stage->isDynamic()) {
                throw new PurchaseTransitionException(SpaApiError::PURCHASE_ROUTE_NOT_CONFIGURED);
            }

            $stage->setStatus(PurchaseStageStatus::SKIPPED);
        }
    }

    /** Согласующая часть маршрута пройдена. */
    private function closeApprovalPart(PurchaseRequest $request, User $actor): void
    {
        if ($request->getStatus() === PurchaseStatus::ON_APPROVAL) {
            $this->setStatus($request, $actor, PurchaseStatus::APPROVED);
        }
    }

    /**
     * Отмотать маршрут назад к этапу: сбросить его решения и всё, что решилось
     * после, и поставить на него указатель.
     *
     * Назначенные согласанты при этом остаются в маршруте — сбрасываются только
     * их решения. Директор поставил их на всю жизнь заявки, а не на один пакет
     * документов, и убрать их значило бы, что возврат в закупки заодно меняет
     * состав согласующих.
     *
     * Сгоревшие решения видны только в истории, поэтому вызывающий обязан
     * записать их туда той же транзакцией.
     *
     * @return list<string> описания сброшенных чужих решений (кроме $exclude)
     */
    private function rewindTo(
        PurchaseRequest $request,
        PurchaseApprovalStage $target,
        ?PurchaseApprovalTask $exclude = null,
    ): array {
        $burned = [];

        foreach ($request->getStages() as $stage) {
            if ($stage->getPosition() < $target->getPosition()) {
                continue;
            }

            foreach ($stage->getTasks() as $task) {
                if ($task->getDecision()->isDecided()) {
                    if ($task !== $exclude) {
                        $burned[] = $task->resolveTitle();
                    }
                    $task->reset();
                }
            }

            $stage->setStatus($stage->getTasks()->isEmpty() && $stage->isDynamic()
                ? PurchaseStageStatus::AWAITING_ASSIGNMENT
                : PurchaseStageStatus::PENDING);
        }

        $request->setStatus(PurchaseStatus::ON_APPROVAL);
        $target->setStatus(PurchaseStageStatus::ACTIVE);

        return $burned;
    }

    /** Сменить статус заявки с записью в историю. */
    private function setStatus(PurchaseRequest $request, User $actor, PurchaseStatus $to): void
    {
        $from = $request->getStatus();
        if ($from === $to) {
            return;
        }

        $request->setStatus($to);
        $this->history->logTransition($request, $actor, $from, $to, PurchaseHistoryAction::STATUS_CHANGED);
    }

    /** Кому сообщить после того, как указатель сдвинулся. */
    private function announce(PurchaseRequest $request, User $actor): void
    {
        match ($request->getStatus()) {
            PurchaseStatus::APPROVED => $this->notifier->notifyApproved($request, $actor),
            PurchaseStatus::INVOICE_PAID => $this->notifier->notifyStatusChanged($request, $actor),
            PurchaseStatus::DELIVERED => $this->notifier->notifyDelivered($request, $actor),
            PurchaseStatus::DONE => $this->notifier->notifyConfirmed($request, $actor),
            default => null,
        };

        if ($request->getCurrentStage() !== null) {
            $this->notifier->notifyStageActivated($request, $actor);
        }
    }

    /**
     * Задача принадлежит этой заявке, не решена и стоит в активном этапе.
     *
     * @throws PurchaseTransitionException
     */
    private function assertActiveTask(PurchaseRequest $request, PurchaseApprovalTask $task): PurchaseApprovalStage
    {
        $stage = $task->getStage();
        if ($stage === null || $stage->getPurchaseRequest()?->getId() !== $request->getId()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_TASK_NOT_FOUND);
        }
        if (!$request->getStatus()->isInRoute()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_INVALID_STATUS);
        }
        if (!$task->isPending() || !$stage->isActive()) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_TASK_NOT_ACTIVE);
        }

        return $stage;
    }

    /**
     * Записать транзакцию, поймав столкновение с параллельным решением.
     *
     * touch() здесь обязателен, а не косметика: оптимистичная блокировка Doctrine
     * проверяет версию только когда обновляется сама строка заявки. Решение по
     * задаче правит строку задачи, и без отметки на заявке двое согласантов
     * параллельного этапа записались бы, не заметив друг друга, — ровно тот
     * случай, ради которого блокировка и заведена.
     *
     * @throws PurchaseTransitionException двое изменили заявку одновременно
     */
    private function save(PurchaseRequest $request): void
    {
        $request->touch();

        try {
            $this->em->flush();
        } catch (OptimisticLockException) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_CONCURRENT_UPDATE);
        }
    }
}
