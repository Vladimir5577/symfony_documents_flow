<?php

declare(strict_types=1);

namespace App\Tests\Service\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseApprovalStage;
use App\Entity\Purchase\PurchaseApprovalTask;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestFile;
use App\Entity\Purchase\PurchaseRequestItem;
use App\Entity\Purchase\PurchaseRouteDefault;
use App\Entity\Purchase\PurchaseRouteTemplate;
use App\Entity\Purchase\PurchaseRouteTemplateStage;
use App\Entity\Purchase\PurchaseRouteTemplateTask;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseHistoryAction;
use App\Enum\Purchase\PurchaseRequestKind;
use App\Enum\Purchase\PurchaseRoleCode;
use App\Enum\Purchase\PurchaseStagePurpose;
use App\Enum\Purchase\PurchaseStageStatus;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\Purchase\PurchaseTaskAssignment;
use App\Enum\Purchase\PurchaseTaskDecision;
use App\Repository\Purchase\PurchaseApproverRoleRepository;
use App\Repository\Purchase\PurchaseRouteDefaultRepository;
use App\Repository\Purchase\PurchaseRouteTemplateRepository;
use App\Repository\User\UserRepository;
use App\Service\Notification\NotificationPublisher;
use App\Service\Purchase\ApprovalRouteBuilder;
use App\Service\Purchase\ApprovalRouteResolver;
use App\Service\Purchase\PurchaseAccess;
use App\Service\Purchase\PurchaseApprovalWorkflow;
use App\Service\Purchase\PurchaseHistoryLogger;
use App\Service\Purchase\PurchaseNotificationPublisher;
use App\Service\Purchase\PurchaseRequestEditor;
use App\Service\Purchase\PurchaseRoster;
use App\Service\Purchase\PurchaseTransitionException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Сценарии согласования заявки на закупку — регламент целиком.
 *
 * Проверяется поведение, а не форма данных: снимок маршрута собирает настоящий
 * ApprovalRouteBuilder, решения проводит настоящий PurchaseApprovalWorkflow. Из
 * инфраструктуры подменены только шина сообщений и репозитории — БД согласованию
 * не нужна.
 *
 * Маршруты берутся из заготовок-фикстур ниже: они описывают тот регламент, который
 * в проекте считают типовым. В коде модуля его нет — маршруты собирают в админке,
 * поэтому «как согласуют закупки» вопрос данных, и здесь эти данные задаёт тест.
 * Проверяется от них не «снимок собрался», а то, что ломается неочевидно: кто
 * становится исполнителем, какие подписи сгорают при возврате, куда встают
 * выбранные разбирающим согласанты и как маршрут проецируется в статус заявки.
 * Правку самих заготовок и правила редактора проверяет PurchaseRouteTemplateTest.
 */
final class PurchaseApprovalFlowTest extends TestCase
{
    /** Позиции этапов маршрутов из фикстур ниже. */

    /** У быстрой заявки отдел закупок первый и единственный. */
    private const STAGE_FAST_SOURCING = 1;

    private const STAGE_TRIAGE = 1;
    private const STAGE_SOURCING = 2;
    private const STAGE_CHECKS = 3;
    private const STAGE_APPROVERS = 4;
    private const STAGE_FINANCE = 5;

    /** Хвост исполнения — только в полном маршруте. */
    private const STAGE_PAYMENT = 6;
    private const STAGE_DELIVERY = 7;
    private const STAGE_CLOSING = 8;

    // Быстрый маршрут

    public function testFastRouteIsSingleSourcingStage(): void
    {
        $request = $this->submitted(PurchaseRequestKind::FAST);

        self::assertSame(PurchaseStatus::ON_APPROVAL, $request->getStatus());
        self::assertSame([
            [self::STAGE_FAST_SOURCING, PurchaseStagePurpose::SOURCING->value, [PurchaseRoleCode::PURCHASE_DEPARTMENT->value]],
        ], $this->shape($request));
    }

    /** Единственный этап закрыт — согласовывать больше нечего, заявка утверждена. */
    public function testFastRouteCompletesOnSingleApproval(): void
    {
        $request = $this->submitted(PurchaseRequestKind::FAST);

        $this->workflow->approveTask($request, $this->taskAt($request, self::STAGE_FAST_SOURCING), $this->user(2));

        self::assertSame(PurchaseStatus::APPROVED, $request->getStatus());
        self::assertNull($request->getCurrentStage());
        self::assertTrue($request->isRouteComplete());
    }

    /** Суммой подача не ограничена: любая сумма идёт по маршруту своего вида. */
    public function testFastSubmitIsNotLimitedByAmount(): void
    {
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::FAST, $author, ['1500000.00']);

        $this->workflow()->submit($request, $author);

        self::assertSame(PurchaseStatus::ON_APPROVAL, $request->getStatus());
    }

    public function testSubmitWithoutItemsIsRejected(): void
    {
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author, []);

        $this->expectTransitionError(SpaApiError::PURCHASE_ITEMS_REQUIRED);
        $this->workflow()->submit($request, $author);
    }

    /**
     * Маршрута по умолчанию нет — подать нельзя.
     *
     * Умолчания в коде нет намеренно: заявка без этапов висела бы на согласовании,
     * не стоя ни у кого, а типовой маршрут из кода был бы вторым ответом на вопрос
     * «как согласуют закупки».
     */
    public function testSubmitWithoutConfiguredRouteIsRejected(): void
    {
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);
        $workflow = $this->workflow(configured: false);

        $this->expectTransitionError(SpaApiError::PURCHASE_ROUTE_NOT_CONFIGURED);
        $workflow->submit($request, $author);
    }

    // Обычный маршрут

    /**
     * Порядок именно такой: отдел закупок готовит документы РАНЬШЕ согласующих,
     * потому что подписывать нечего, пока нет поставщика, цен и договора.
     *
     * Бухгалтерия и юристы стоят двумя задачами ОДНОГО этапа — параллельность
     * теперь состав этапа, а не совпадение номеров позиций.
     */
    public function testStandardRouteShape(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD);

        self::assertSame([
            [self::STAGE_TRIAGE, PurchaseStagePurpose::TRIAGE->value, [PurchaseRoleCode::DIRECTOR->value]],
            [self::STAGE_SOURCING, PurchaseStagePurpose::SOURCING->value, [PurchaseRoleCode::PURCHASE_DEPARTMENT->value]],
            [self::STAGE_CHECKS, PurchaseStagePurpose::SIGN_OFF->value, [
                PurchaseRoleCode::ACCOUNTING->value,
                PurchaseRoleCode::LEGAL->value,
            ]],
            [self::STAGE_APPROVERS, PurchaseStagePurpose::SIGN_OFF->value, []],
            [self::STAGE_FINANCE, PurchaseStagePurpose::SIGN_OFF->value, [PurchaseRoleCode::FINANCE_DIRECTOR->value]],
        ], $this->shape($request));
        self::assertSame(self::STAGE_TRIAGE, $request->getCurrentStage()?->getPosition());
    }

    /**
     * Этап согласантов при подаче пуст: до решения разбирающего неизвестно, кто
     * они и есть ли вообще. Этап при этом виден и помечен ожиданием назначения —
     * это не провал в маршруте.
     */
    public function testDynamicStageIsEmptyUntilTriageDecides(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD);
        $stage = $this->stageAt($request, self::STAGE_APPROVERS);

        self::assertCount(0, $stage->getTasks());
        self::assertSame(PurchaseStageStatus::AWAITING_ASSIGNMENT, $stage->getStatus());
        self::assertTrue($stage->isDynamic());
        self::assertSame(PurchaseRoleCode::PROFILE_DEPUTY, $stage->getCandidateRoleCode());
    }

    /** Бухгалтерия и юристы параллельны: этап уходит, когда подписали оба. */
    public function testParallelStageWaitsForBothSignatures(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD);
        $this->approveThrough($request, self::STAGE_SOURCING);

        $this->workflow->approveTask(
            $request,
            $this->taskAt($request, self::STAGE_CHECKS, PurchaseRoleCode::ACCOUNTING),
            $this->user(4),
        );

        self::assertSame(self::STAGE_CHECKS, $request->getCurrentStage()?->getPosition());

        $this->workflow->approveTask(
            $request,
            $this->taskAt($request, self::STAGE_CHECKS, PurchaseRoleCode::LEGAL),
            $this->user(5),
        );

        // Этап согласантов пуст — разбирающий никого не выбрал, и указатель его
        // проезжает, а не встаёт ждать тех, кого не будет.
        self::assertSame(self::STAGE_FINANCE, $request->getCurrentStage()?->getPosition());
        self::assertSame(
            PurchaseStageStatus::SKIPPED,
            $this->stageAt($request, self::STAGE_APPROVERS)->getStatus(),
        );
    }

    /**
     * Исполнитель — тот, кто закрыл этап ресёрча: он вёл поиск поставщика и
     * документы. Не первый коснувшийся исполнения и не тот, кто платит.
     */
    public function testExecutorIsTheOneWhoClosedSourcingStage(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD);
        $director = $this->user(2);
        $buyer = $this->user(3);

        $this->workflow->approveTask($request, $this->taskAt($request, self::STAGE_TRIAGE), $director);

        self::assertNull($request->getExecutor(), 'подпись разбирающего исполнителя не назначает');

        $this->workflow->approveTask($request, $this->taskAt($request, self::STAGE_SOURCING), $buyer);

        self::assertSame($buyer, $request->getExecutor());
    }

    public function testApprovingOutOfTurnIsRejected(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD);

        $this->expectTransitionError(SpaApiError::PURCHASE_TASK_NOT_ACTIVE);
        $this->workflow->approveTask($request, $this->taskAt($request, self::STAGE_FINANCE), $this->user(9));
    }

    /**
     * Требование файла живёт на задаче, а не в конвейере: у быстрого маршрута
     * задачи «договор» нет, и требовать с него договор не за что.
     */
    public function testTaskRequiringFileBlocksApproval(): void
    {
        $request = $this->submitted(PurchaseRequestKind::FAST);
        $task = $this->taskAt($request, self::STAGE_FAST_SOURCING);
        $task->setRequiresFileType(PurchaseFileType::CONTRACT);

        $this->expectTransitionError(SpaApiError::PURCHASE_TASK_FILE_REQUIRED);
        $this->workflow->approveTask($request, $task, $this->user(2));
    }

    // Разбор

    /**
     * Согласанты встают на свой этап — далеко позади отдела закупок, — а заявка
     * после решения разбирающего уезжает именно в закупки. Автор согласантом не
     * становится, повторы схлопываются.
     */
    public function testTriageAssignsApproversToDynamicStage(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD);
        $author = $request->getCreatedBy();
        self::assertInstanceOf(User::class, $author);
        $deputy = $this->user(7);
        $stage = $this->stageAt($request, self::STAGE_APPROVERS);

        $this->workflow->triage(
            $request,
            $this->taskAt($request, self::STAGE_TRIAGE),
            $this->user(2),
            [],
            [(int) $stage->getId() => [$deputy, $deputy, $author]],
        );

        self::assertCount(1, $stage->getTasks(), 'автор и дубликаты в согласанты не попадают');
        $assigned = $stage->getTasks()->first();
        self::assertInstanceOf(PurchaseApprovalTask::class, $assigned);
        self::assertSame($deputy, $assigned->getAssigneeUser());
        self::assertSame(PurchaseStageStatus::PENDING, $stage->getStatus());
        self::assertSame(self::STAGE_SOURCING, $request->getCurrentStage()?->getPosition());
    }

    /**
     * Назначить согласантов можно только пока активен разбор: дальше выбирать их
     * некому, а указатель поехал бы назад.
     */
    public function testApproversCannotBeAssignedAfterTriage(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD);
        $this->approveThrough($request, self::STAGE_TRIAGE);

        $this->expectTransitionError(SpaApiError::PURCHASE_TASK_NOT_ACTIVE);
        $this->workflow->triage(
            $request,
            $this->taskAt($request, self::STAGE_SOURCING),
            $this->user(3),
            [],
            [],
        );
    }

    /** Снятая позиция в сумму не идёт, количество берётся утверждённое. */
    public function testTriageEditsAffectTotalAmount(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD, ['100.00', '200.00']);

        $this->workflow->triage(
            $request,
            $this->taskAt($request, self::STAGE_TRIAGE),
            $this->user(2),
            [
                1 => ['included' => true, 'quantity' => '3.000'],
                2 => ['included' => false, 'quantity' => null],
            ],
            [],
        );

        self::assertSame(300.0, $request->getTotalAmount());
    }

    /** Заявка без единой позиции — это отказ, и оформлять его надо отказом. */
    public function testTriageCannotExcludeEveryItem(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD);

        $this->expectTransitionError(SpaApiError::PURCHASE_ITEMS_REQUIRED);
        $this->workflow->triage(
            $request,
            $this->taskAt($request, self::STAGE_TRIAGE),
            $this->user(2),
            [1 => ['included' => false, 'quantity' => null]],
            [],
        );
    }

    // Возврат в отдел закупок

    /**
     * Бухгалтерия и юристы бракуют пакет документов, а не заявку: она остаётся на
     * согласовании и откатывается на этап закупок. Решения, успевшие лечь после
     * этого этапа, сбрасываются — пакет будет другой.
     */
    public function testReturnToSourcingResetsLaterSignatures(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD);
        $this->approveThrough($request, self::STAGE_SOURCING);
        $this->workflow->approveTask(
            $request,
            $this->taskAt($request, self::STAGE_CHECKS, PurchaseRoleCode::ACCOUNTING),
            $this->user(4),
        );

        $this->workflow->returnToSourcing(
            $request,
            $this->taskAt($request, self::STAGE_CHECKS, PurchaseRoleCode::LEGAL),
            $this->user(5),
            'Договор без реквизитов',
        );

        self::assertSame(PurchaseStatus::ON_APPROVAL, $request->getStatus());
        self::assertSame(self::STAGE_SOURCING, $request->getCurrentStage()?->getPosition());
        self::assertSame(
            PurchaseTaskDecision::APPROVED,
            $this->taskAt($request, self::STAGE_TRIAGE)->getDecision(),
            'решение разбирающего стоит раньше закупок и не сгорает',
        );
        self::assertSame(
            PurchaseTaskDecision::PENDING,
            $this->taskAt($request, self::STAGE_CHECKS, PurchaseRoleCode::ACCOUNTING)->getDecision(),
            'подпись бухгалтерии легла после закупок и сброшена',
        );
    }

    /**
     * Выбранные согласанты возврат переживают: их поставили на всю жизнь заявки,
     * а не на один пакет документов. Сбрасывается только их решение.
     */
    public function testReturnToSourcingKeepsAssignedApprovers(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD);
        $deputy = $this->user(7);
        $stage = $this->stageAt($request, self::STAGE_APPROVERS);

        $this->workflow->triage(
            $request,
            $this->taskAt($request, self::STAGE_TRIAGE),
            $this->user(2),
            [],
            [(int) $stage->getId() => [$deputy]],
        );
        $this->workflow->approveTask($request, $this->taskAt($request, self::STAGE_SOURCING), $this->user(3));
        $this->workflow->approveTask(
            $request,
            $this->taskAt($request, self::STAGE_CHECKS, PurchaseRoleCode::ACCOUNTING),
            $this->user(4),
        );

        $this->workflow->returnToSourcing(
            $request,
            $this->taskAt($request, self::STAGE_CHECKS, PurchaseRoleCode::LEGAL),
            $this->user(5),
            'Счёт не тот',
        );

        self::assertCount(1, $stage->getTasks(), 'назначенный согласант остаётся в маршруте');
        self::assertSame(PurchaseStageStatus::PENDING, $stage->getStatus());
    }

    public function testReturnToSourcingRequiresComment(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD);
        $this->approveThrough($request, self::STAGE_SOURCING);

        $this->expectTransitionError(SpaApiError::PURCHASE_COMMENT_REQUIRED);
        $this->workflow->returnToSourcing(
            $request,
            $this->taskAt($request, self::STAGE_CHECKS, PurchaseRoleCode::ACCOUNTING),
            $this->user(4),
            '   ',
        );
    }

    /** У быстрой заявки закупки первые — возвращать документы некуда. */
    public function testReturnToSourcingImpossibleOnFastRoute(): void
    {
        $request = $this->submitted(PurchaseRequestKind::FAST);

        $this->expectTransitionError(SpaApiError::PURCHASE_TASK_NOT_ACTIVE);
        $this->workflow->returnToSourcing(
            $request,
            $this->taskAt($request, self::STAGE_FAST_SOURCING),
            $this->user(2),
            'Не хочу',
        );
    }

    // Отказ, повторная подача, отзыв подписи

    public function testRejectSendsRequestBackToAuthor(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD);
        $task = $this->taskAt($request, self::STAGE_TRIAGE);

        $this->workflow->rejectTask($request, $task, $this->user(2), 'Не сейчас');

        self::assertSame(PurchaseStatus::REJECTED, $request->getStatus());
        self::assertSame(PurchaseTaskDecision::REJECTED, $task->getDecision());
        self::assertSame('Не сейчас', $task->getComment());
    }

    /** Повторная подача начинает согласование с нуля: состав и сумма могли измениться. */
    public function testResubmitRebuildsRouteFromScratch(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD);
        $author = $request->getCreatedBy();
        self::assertInstanceOf(User::class, $author);

        $this->workflow->rejectTask(
            $request,
            $this->taskAt($request, self::STAGE_TRIAGE),
            $this->user(2),
            'Доработать',
        );
        $this->workflow->submit($request, $author);
        $this->assignIds($request);

        self::assertSame(PurchaseStatus::ON_APPROVAL, $request->getStatus());
        self::assertCount(5, $request->getStages());
        self::assertSame(self::STAGE_TRIAGE, $request->getCurrentStage()?->getPosition());
        foreach ($request->getAllTasks() as $task) {
            self::assertTrue($task->isPending(), 'после пересборки все задачи ждут решения');
        }
    }

    /**
     * Персональный откат: подпись закрывает этап сразу, окна «на передумать» не
     * остаётся, а ошибиться тогглом легко. Сбрасывается своя задача и всё, что
     * успело решиться после неё.
     */
    public function testRevokeResetsOwnAndLaterSignatures(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD);
        $this->approveThrough($request, self::STAGE_CHECKS);

        $financeTask = $this->taskAt($request, self::STAGE_FINANCE);
        $financeDirector = $this->user(6);
        $this->workflow->approveTask($request, $financeTask, $financeDirector);

        self::assertSame(PurchaseStatus::APPROVED, $request->getStatus());

        $this->workflow->revokeTask($request, $financeTask, $financeDirector);

        self::assertSame(PurchaseStatus::ON_APPROVAL, $request->getStatus());
        self::assertSame(self::STAGE_FINANCE, $request->getCurrentStage()?->getPosition());
        self::assertSame(PurchaseTaskDecision::PENDING, $financeTask->getDecision());
        self::assertSame(
            PurchaseTaskDecision::APPROVED,
            $this->taskAt($request, self::STAGE_CHECKS, PurchaseRoleCode::LEGAL)->getDecision(),
            'подписи раньше отозванной не сгорают',
        );
    }

    /**
     * Кнопка отката предлагает последнюю из моих подписей.
     *
     * Отзыв отматывает маршрут к этапу подписи, и кнопка, снимающая первую, сожгла
     * бы всё, что после неё подписали остальные, — а человек нажимал «я передумал».
     */
    public function testRevokeOffersTheLatestOfMySignatures(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD);
        $director = $this->user(2);

        $this->workflow->approveTask($request, $this->taskAt($request, self::STAGE_TRIAGE), $director);
        $this->workflow->approveTask($request, $this->taskAt($request, self::STAGE_SOURCING), $this->user(3));
        $late = $this->taskAt($request, self::STAGE_CHECKS, PurchaseRoleCode::ACCOUNTING);
        $this->workflow->approveTask($request, $late, $director);

        self::assertSame($late, $this->access()->findMyRevokableTask($request, $director));
    }

    /** Чужую подпись снимает только отзыв маршрута отделом закупок. */
    public function testForeignSignatureCannotBeRevoked(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD);
        $task = $this->taskAt($request, self::STAGE_TRIAGE);
        $this->workflow->approveTask($request, $task, $this->user(2));

        $this->expectTransitionError(SpaApiError::PURCHASE_TASK_NOT_REVOKABLE);
        $this->workflow->revokeTask($request, $task, $this->user(3));
    }

    /**
     * В историю пишется каждое действие, а не только смена статуса: согласование
     * целиком идёт внутри ON_APPROVAL, а задачи при повторной подаче
     * пересобираются — след «кто что нажал» должен жить отдельно от них.
     */
    public function testHistoryKeepsEveryAction(): void
    {
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::FAST, $author);
        $workflow = $this->workflow();

        $workflow->logCreated($request, $author);
        $workflow->submit($request, $author);
        $this->assignIds($request);
        $workflow->approveTask(
            $request,
            $this->taskAt($request, self::STAGE_FAST_SOURCING),
            $this->user(2),
            'Куплено',
        );

        self::assertSame([
            PurchaseHistoryAction::CREATED->value,
            PurchaseHistoryAction::SUBMITTED->value,
            PurchaseHistoryAction::TASK_APPROVED->value,
            PurchaseHistoryAction::STATUS_CHANGED->value,
        ], $this->actions($request));
    }

    // Смена маршрута

    /**
     * Маршрут меняют на разборе, и снимок собирается заново. Заявку это не
     * двигает: разбирающий остаётся на разборе нового маршрута.
     */
    public function testRouteChangeRebuildsSnapshotAtTriage(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD);

        $this->workflow->changeRoute(
            $request,
            $this->taskAt($request, self::STAGE_TRIAGE),
            $this->fullRoute(),
            $this->user(2),
        );
        $this->assignIds($request);

        self::assertCount(8, $request->getStages(), 'снимок собран по новому маршруту');
        self::assertSame(self::STAGE_TRIAGE, $request->getCurrentStage()?->getPosition());
        self::assertSame(PurchaseStatus::ON_APPROVAL, $request->getStatus());
        self::assertSame('Полный маршрут', $request->getAppliedRouteTemplateName());
        self::assertContains(PurchaseHistoryAction::ROUTE_CHANGED->value, $this->actions($request));
    }

    /** Дальше разбора менять нельзя: в маршруте уже лежат чужие решения. */
    public function testRouteCannotBeChangedAfterTriage(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD);
        $this->approveThrough($request, self::STAGE_TRIAGE);

        $this->expectTransitionError(SpaApiError::PURCHASE_ROUTE_NOT_CHANGEABLE);
        $this->workflow->changeRoute(
            $request,
            $this->taskAt($request, self::STAGE_SOURCING),
            $this->fullRoute(),
            $this->user(3),
        );
    }

    // Исполнение этапами

    /**
     * Согласование кончилось — заявка APPROVED, хотя маршрут ещё не пройден: дальше
     * идут этапы исполнения. Иначе оплату ждала бы заявка, по статусу всё ещё
     * «на согласовании».
     */
    public function testApprovalPartClosesBeforeExecution(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD, ['100.00'], full: true);
        $this->approveThrough($request, self::STAGE_FINANCE);

        self::assertSame(PurchaseStatus::APPROVED, $request->getStatus());
        self::assertSame(self::STAGE_PAYMENT, $request->getCurrentStage()?->getPosition());
        self::assertFalse($request->isRouteComplete());
    }

    /** Статус — проекция маршрута: закрылся этап оплаты, значит оплачено. */
    public function testPaymentStageMovesStatusToInvoicePaid(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD, ['100.00'], full: true);
        $this->approveThrough($request, self::STAGE_PAYMENT);

        self::assertSame(PurchaseStatus::INVOICE_PAID, $request->getStatus());
        self::assertSame(self::STAGE_DELIVERY, $request->getCurrentStage()?->getPosition());
    }

    /**
     * Поставку принимает автор — так настроен маршрут, а не зашито в коде.
     * Пройденное закрытие уводит заявку в архив.
     */
    public function testFullRouteEndsInDone(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD, ['100.00'], full: true);
        $author = $request->getCreatedBy();
        self::assertInstanceOf(User::class, $author);

        $this->approveThrough($request, self::STAGE_PAYMENT);

        $delivery = $this->taskAt($request, self::STAGE_DELIVERY);
        self::assertSame(PurchaseTaskAssignment::AUTHOR, $delivery->getAssignmentType());
        self::assertTrue($delivery->isAddressedTo($author));

        $this->workflow->approveTask($request, $delivery, $author);
        self::assertSame(PurchaseStatus::DELIVERED, $request->getStatus());

        $request->addFile($this->updFile());
        $this->workflow->approveTask($request, $this->taskAt($request, self::STAGE_CLOSING), $this->user(3));

        self::assertSame(PurchaseStatus::DONE, $request->getStatus());
        self::assertTrue($request->isRouteComplete());
    }

    /**
     * Без УПД заявку не закрыть. Это требование файла на задаче закрытия, а не
     * условие перехода статуса: перенести его на другой этап — правка в админке.
     */
    public function testClosingRequiresUpd(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD, ['100.00'], full: true);
        $author = $request->getCreatedBy();
        self::assertInstanceOf(User::class, $author);

        $this->approveThrough($request, self::STAGE_PAYMENT);
        $this->workflow->approveTask($request, $this->taskAt($request, self::STAGE_DELIVERY), $author);

        $this->expectTransitionError(SpaApiError::PURCHASE_TASK_FILE_REQUIRED);
        $this->workflow->approveTask($request, $this->taskAt($request, self::STAGE_CLOSING), $this->user(3));
    }

    /**
     * С исполнения вернуть автору нельзя: товар уже оплачен, и «вернуть» означало
     * бы не решение по заявке, а потерянные деньги.
     */
    public function testRejectIsNotAllowedOnExecutionStage(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD, ['100.00'], full: true);
        $this->approveThrough($request, self::STAGE_FINANCE);

        $this->expectTransitionError(SpaApiError::PURCHASE_REJECT_NOT_ALLOWED);
        $this->workflow->rejectTask(
            $request,
            $this->taskAt($request, self::STAGE_PAYMENT),
            $this->user(6),
            'Передумали',
        );
    }

    /** Подпись об оплате не отзывается: деньги уже ушли. */
    public function testExecutionSignatureCannotBeRevoked(): void
    {
        $request = $this->submitted(PurchaseRequestKind::STANDARD, ['100.00'], full: true);
        $financeDirector = $this->user(6);

        $this->approveThrough($request, self::STAGE_FINANCE);
        $payment = $this->taskAt($request, self::STAGE_PAYMENT);
        $this->workflow->approveTask($request, $payment, $financeDirector);

        $this->expectTransitionError(SpaApiError::PURCHASE_INVALID_STATUS);
        $this->workflow->revokeTask($request, $payment, $financeDirector);
    }

    // Обвязка

    private PurchaseApprovalWorkflow $workflow;

    /**
     * Настоящие сервисы на подменённой инфраструктуре: согласование не делает
     * запросов, а flush в мок-менеджере просто ничего не делает.
     */
    private function workflow(bool $configured = true): PurchaseApprovalWorkflow
    {
        $em = $this->createStub(EntityManagerInterface::class);

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static fn (object $message, array $stamps = []): Envelope => new Envelope($message),
        );

        $users = $this->createStub(UserRepository::class);
        $users->method('findByRoleName')->willReturn([]);

        // Носителей ролей в сценариях нет: маршрут адресует задачи ролям, а
        // закрывает кто угодно — воркфлоу проверяет указатель, а не права.
        $approverRoles = $this->createStub(PurchaseApproverRoleRepository::class);
        $approverRoles->method('findRoleCodesForUser')->willReturn([]);
        $approverRoles->method('findUsersByRoleCodes')->willReturn([]);

        $roster = new PurchaseRoster($approverRoles);

        $templates = $this->createStub(PurchaseRouteTemplateRepository::class);
        $templates->method('findActiveForKind')->willReturnCallback(
            fn (PurchaseRequestKind $kind): array => [$this->routeFor($kind), $this->fullRoute()],
        );

        $defaults = $this->createStub(PurchaseRouteDefaultRepository::class);
        $defaults->method('findByKind')->willReturnCallback(
            fn (PurchaseRequestKind $kind): ?PurchaseRouteDefault => $configured
                ? (new PurchaseRouteDefault())->setKind($kind)->setTemplate($this->routeFor($kind))
                : null,
        );

        $history = new PurchaseHistoryLogger($em);

        $this->workflow = new PurchaseApprovalWorkflow(
            $em,
            new PurchaseNotificationPublisher(new NotificationPublisher($bus), $users, $roster),
            new ApprovalRouteResolver($templates, $defaults),
            new ApprovalRouteBuilder($em),
            $history,
            new PurchaseRequestEditor($em, $history),
        );

        return $this->workflow;
    }

    /** Права на заявку. Ролей модуля ни у кого нет — задачи адресные. */
    private function access(): PurchaseAccess
    {
        $approverRoles = $this->createStub(PurchaseApproverRoleRepository::class);
        $approverRoles->method('findRoleCodesForUser')->willReturn([]);

        return new PurchaseAccess(new PurchaseRoster($approverRoles));
    }

    /**
     * Поданная заявка с проставленными id — их обычно проставляет БД, а
     * назначение согласантов адресуется этапу по id.
     *
     * @param list<string> $prices
     */
    private function submitted(
        PurchaseRequestKind $kind,
        array $prices = ['100.00'],
        bool $full = false,
    ): PurchaseRequest {
        $author = $this->user(1);
        $request = $this->request($kind, $author, $prices);
        if ($full) {
            $request->setRouteTemplate($this->fullRoute());
        }

        $this->workflow()->submit($request, $author);
        $this->assignIds($request);

        return $request;
    }

    /** Закрыть согласием все этапы вплоть до указанного включительно. */
    private function approveThrough(PurchaseRequest $request, int $position): void
    {
        $guard = 0;
        while (($stage = $request->getCurrentStage()) !== null && $stage->getPosition() <= $position) {
            self::assertLessThan(50, ++$guard, 'указатель маршрута не двигается');

            foreach ($stage->getPendingTasks() as $task) {
                $actor = $task->getAssignmentType() === PurchaseTaskAssignment::AUTHOR
                    ? $request->getCreatedBy()
                    : $this->user($stage->getPosition() + 1);
                self::assertInstanceOf(User::class, $actor);

                $this->workflow->approveTask($request, $task, $actor);
            }
        }
    }

    private function routeFor(PurchaseRequestKind $kind): PurchaseRouteTemplate
    {
        return $kind === PurchaseRequestKind::FAST ? $this->fastRoute() : $this->standardRoute();
    }

    /** Быстрый маршрут: отдел закупок первый и единственный — дальше ведёт заявку сам. */
    private function fastRoute(): PurchaseRouteTemplate
    {
        return $this->route('FAST', 'Быстрый маршрут', [PurchaseRequestKind::FAST], [
            $this->stage(PurchaseStagePurpose::SOURCING, [
                $this->roleTask(PurchaseRoleCode::PURCHASE_DEPARTMENT),
            ]),
        ]);
    }

    /**
     * Обычный маршрут: разбор → отдел закупок → бухгалтерия и юристы →
     * профильные замы → финансовый директор.
     *
     * Порядок именно такой: отдел закупок делает ресёрч и готовит документы
     * РАНЬШЕ согласующих, потому что подписывать нечего, пока нет поставщика, цен
     * и договора. Замы стоят позади закупок — они подписывают готовый пакет,
     * хотя выбирают их ещё на разборе.
     */
    private function standardRoute(): PurchaseRouteTemplate
    {
        return $this->route('STANDARD', 'Обычный маршрут', [PurchaseRequestKind::STANDARD], [
            $this->stage(PurchaseStagePurpose::TRIAGE, [$this->roleTask(PurchaseRoleCode::DIRECTOR)]),
            $this->stage(PurchaseStagePurpose::SOURCING, [$this->roleTask(PurchaseRoleCode::PURCHASE_DEPARTMENT)]),
            // Один этап, две задачи — это и есть параллельные подписи.
            $this->stage(PurchaseStagePurpose::SIGN_OFF, [
                $this->roleTask(PurchaseRoleCode::ACCOUNTING),
                $this->roleTask(PurchaseRoleCode::LEGAL),
            ]),
            $this->stage(PurchaseStagePurpose::SIGN_OFF, [
                $this->dynamicTask(PurchaseRoleCode::PROFILE_DEPUTY),
            ], 'Профильные замы'),
            $this->stage(PurchaseStagePurpose::SIGN_OFF, [$this->roleTask(PurchaseRoleCode::FINANCE_DIRECTOR)]),
        ]);
    }

    /**
     * Тот же маршрут с хвостом исполнения: оплата, поставка и закрытие — такие же
     * этапы, а не отдельная цепочка статусов.
     */
    private function fullRoute(): PurchaseRouteTemplate
    {
        $template = $this->standardRoute();
        $template->setCode('FULL')->setName('Полный маршрут');

        $payment = $this->stage(PurchaseStagePurpose::PAYMENT, [
            $this->roleTask(PurchaseRoleCode::FINANCE_DIRECTOR),
        ]);
        $delivery = $this->stage(PurchaseStagePurpose::DELIVERY, [
            (new PurchaseRouteTemplateTask())
                ->setPosition(1)
                ->setAssignmentType(PurchaseTaskAssignment::AUTHOR),
        ]);
        $closing = $this->stage(PurchaseStagePurpose::CLOSING, [
            $this->roleTask(PurchaseRoleCode::PURCHASE_DEPARTMENT)
                ->setRequiresFileType(PurchaseFileType::UPD),
        ]);

        // Вернуть автору с исполнения нельзя: деньги уже потрачены.
        $position = $template->getStages()->count();
        foreach ([$payment, $delivery, $closing] as $stage) {
            $template->addStage($stage->setPosition(++$position)->setAllowsReject(false));
        }

        return $template;
    }

    /**
     * @param list<PurchaseRequestKind> $kinds
     * @param list<PurchaseRouteTemplateStage> $stages
     */
    private function route(string $code, string $name, array $kinds, array $stages): PurchaseRouteTemplate
    {
        $template = (new PurchaseRouteTemplate())
            ->setCode($code)
            ->setName($name)
            ->setAllowedKinds($kinds);

        $position = 0;
        foreach ($stages as $stage) {
            $template->addStage($stage->setPosition(++$position));
        }

        return $template;
    }

    /** @param list<PurchaseRouteTemplateTask> $tasks */
    private function stage(
        PurchaseStagePurpose $purpose,
        array $tasks,
        ?string $title = null,
    ): PurchaseRouteTemplateStage {
        $stage = (new PurchaseRouteTemplateStage())->setPurpose($purpose)->setTitle($title);

        $position = 0;
        foreach ($tasks as $task) {
            $stage->addTask($task->setPosition(++$position));
        }

        return $stage;
    }

    private function roleTask(PurchaseRoleCode $code): PurchaseRouteTemplateTask
    {
        return (new PurchaseRouteTemplateTask())
            ->setAssignmentType(PurchaseTaskAssignment::ROLE)
            ->setRoleCode($code)
            ->setTitle($code->getLabel());
    }

    private function dynamicTask(PurchaseRoleCode $pool): PurchaseRouteTemplateTask
    {
        return (new PurchaseRouteTemplateTask())
            ->setAssignmentType(PurchaseTaskAssignment::DYNAMIC_USERS)
            ->setCandidateRoleCode($pool);
    }

    /**
     * Черновик с позициями по одной штуке: id проставляются вручную, потому что
     * правки состава приходят с фронта ключами по id позиции.
     *
     * @param list<string> $prices
     */
    private function request(PurchaseRequestKind $kind, User $author, array $prices = ['100.00']): PurchaseRequest
    {
        $request = new PurchaseRequest();
        $request->setTitle('Картриджи для принтера')
            ->setCreatedAs($kind)
            ->setCreatedBy($author);
        self::setId($request, 500);

        $position = 0;
        foreach ($prices as $price) {
            ++$position;
            $item = (new PurchaseRequestItem())
                ->setName('Позиция ' . $position)
                ->setUnit('шт')
                ->setQuantity('1.000')
                ->setEstimatedPrice($price)
                ->setPosition($position);
            self::setId($item, $position);
            $request->addItem($item);
        }

        return $request;
    }

    private function updFile(): PurchaseRequestFile
    {
        return (new PurchaseRequestFile())
            ->setType(PurchaseFileType::UPD)
            ->setOriginalName('upd.pdf')
            ->setStorageKey('purchase/500/upd.pdf');
    }

    private function user(int $id): User
    {
        $user = new User();
        self::setId($user, $id);

        return $user;
    }

    /**
     * Форма маршрута: позиция, назначение и роли задач каждого этапа.
     *
     * @return list<array{0: int, 1: string, 2: list<string|null>}>
     */
    private function shape(PurchaseRequest $request): array
    {
        $shape = [];
        foreach ($request->getStages() as $stage) {
            $roles = [];
            foreach ($stage->getTasks() as $task) {
                $roles[] = $task->getRoleCode()?->value;
            }
            $shape[] = [$stage->getPosition(), $stage->getPurpose()->value, $roles];
        }

        return $shape;
    }

    /** @return list<string|null> */
    private function actions(PurchaseRequest $request): array
    {
        return array_values(array_map(
            static fn ($entry): ?string => $entry->getAction()?->value,
            $request->getHistory()->toArray(),
        ));
    }

    private function stageAt(PurchaseRequest $request, int $position): PurchaseApprovalStage
    {
        $stage = $request->findStageByPosition($position);
        self::assertInstanceOf(
            PurchaseApprovalStage::class,
            $stage,
            sprintf('В маршруте нет этапа на позиции %d', $position),
        );

        return $stage;
    }

    /** Задача этапа; при параллельных задачах нужна ещё и роль. */
    private function taskAt(
        PurchaseRequest $request,
        int $position,
        ?PurchaseRoleCode $code = null,
    ): PurchaseApprovalTask {
        foreach ($this->stageAt($request, $position)->getTasks() as $task) {
            if ($code === null || $task->getRoleCode() === $code) {
                return $task;
            }
        }

        self::fail(sprintf('В этапе %d нет подходящей задачи', $position));
    }

    /** Автоинкремент БД: этапам и задачам нужны id, назначение адресуется по ним. */
    private function assignIds(PurchaseRequest $request): void
    {
        $stageId = 1000;
        $taskId = 2000;

        foreach ($request->getStages() as $stage) {
            self::setId($stage, ++$stageId);
            foreach ($stage->getTasks() as $task) {
                self::setId($task, ++$taskId);
            }
        }
    }

    private function expectTransitionError(string $errorCode): void
    {
        $this->expectException(PurchaseTransitionException::class);
        $this->expectExceptionMessage($errorCode);
    }

    /** Автоинкремент подменяем руками: сущности живут без БД. */
    private static function setId(object $entity, int $id): void
    {
        $class = new \ReflectionClass($entity);
        while (!$class->hasProperty('id')) {
            $parent = $class->getParentClass();
            self::assertNotFalse($parent, sprintf('У %s нет свойства id', $entity::class));
            $class = $parent;
        }

        $class->getProperty('id')->setValue($entity, $id);
    }
}
