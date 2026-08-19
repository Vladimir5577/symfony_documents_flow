<?php

declare(strict_types=1);

namespace App\Tests\Service\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseApprovalStep;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestItem;
use App\Entity\Purchase\PurchaseRouteTemplate;
use App\Entity\Purchase\PurchaseRouteTemplateStep;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseApproverKind;
use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseHistoryAction;
use App\Enum\Purchase\PurchaseRequestKind;
use App\Enum\Purchase\PurchaseRoleCode;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\Purchase\PurchaseStepDecision;
use App\Enum\Purchase\PurchaseStepPurpose;
use App\Repository\Purchase\PurchaseApproverRoleRepository;
use App\Repository\Purchase\PurchaseRouteTemplateRepository;
use App\Repository\Purchase\PurchaseSettingRepository;
use App\Repository\User\UserRepository;
use App\Service\Notification\NotificationPublisher;
use App\Service\Purchase\ApprovalRouteBuilder;
use App\Service\Purchase\PurchaseNotificationPublisher;
use App\Service\Purchase\PurchaseRequestService;
use App\Service\Purchase\PurchaseRoster;
use App\Service\Purchase\PurchaseTransitionException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Сценарии согласования заявки на закупку — регламент целиком.
 *
 * Проверяется поведение, а не форма данных: маршрут собирает настоящий
 * ApprovalRouteBuilder, решения проводит настоящий PurchaseRequestService.
 * Из инфраструктуры подменены только шина сообщений и репозитории — БД
 * согласованию не нужна, указатель маршрута считается в памяти по шагам.
 *
 * Маршруты берутся из заготовок-фикстур ниже — они описывают тот регламент,
 * который в проекте считают типовым. В коде модуля его нет: маршрут собирают в
 * админке, поэтому «как согласуют закупки» — вопрос данных, и здесь эти данные
 * задаёт тест. Проверяется от них не «маршрут собрался», а то, что ломается
 * неочевидно: кто становится исполнителем, какие подписи сгорают при возврате и
 * куда встают назначенные директором замы. Правку самих заготовок и правила
 * редактора проверяет PurchaseRouteTemplateTest.
 */
final class PurchaseApprovalFlowTest extends TestCase
{
    /** Позиции маршрутов из фикстур ниже: fastRoute() и standardRoute(). */

    /** У быстрой заявки отдел закупок первый и единственный. */
    private const POSITION_FAST_DEPARTMENT = 1;

    private const POSITION_DIRECTOR = 1;
    private const POSITION_DEPARTMENT = 2;
    private const POSITION_CHECKS = 3;
    private const POSITION_APPROVERS = 4;
    private const POSITION_DIRECTOR_FINAL = 5;
    private const POSITION_FINANCE = 6;

    // Быстрый маршрут

    public function testFastRouteIsSingleDepartmentStep(): void
    {
        $service = $this->service();
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::FAST, $author);

        $service->submit($request, $author);

        self::assertSame(PurchaseStatus::ON_APPROVAL, $request->getStatus());
        self::assertSame(
            [[self::POSITION_FAST_DEPARTMENT, PurchaseRoleCode::PURCHASE_DEPARTMENT->value]],
            $this->shape($request),
        );
    }

    /** Единственный шаг закрыт — согласовывать больше нечего, заявка утверждена. */
    public function testFastRouteCompletesOnSingleApproval(): void
    {
        $service = $this->service();
        $author = $this->user(1);
        $buyer = $this->user(2);
        $request = $this->request(PurchaseRequestKind::FAST, $author);

        $service->submit($request, $author);
        $service->approveStep($request, $this->stepAt($request, self::POSITION_FAST_DEPARTMENT), $buyer);

        self::assertSame(PurchaseStatus::APPROVED, $request->getStatus());
        self::assertNull($request->getCurrentPosition());
        self::assertTrue($request->isRouteComplete());
    }

    /** Потолок быстрой заявки стережёт сервер: спрятанная кнопка защитой не является. */
    public function testFastSubmitAboveCapIsRejected(): void
    {
        $service = $this->service(fastMaxAmount: 1000.0);
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::FAST, $author, ['1500.00']);

        $this->expectTransitionError(SpaApiError::PURCHASE_FAST_LIMIT_EXCEEDED);
        $service->submit($request, $author);
    }

    public function testSubmitWithoutItemsIsRejected(): void
    {
        $service = $this->service();
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author, []);

        $this->expectTransitionError(SpaApiError::PURCHASE_ITEMS_REQUIRED);
        $service->submit($request, $author);
    }

    // Обычный маршрут

    /**
     * Порядок именно такой: отдел закупок готовит документы РАНЬШЕ согласующих,
     * потому что подписывать нечего, пока нет поставщика, цен и договора.
     */
    public function testStandardRouteShape(): void
    {
        $service = $this->service();
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);

        $service->submit($request, $author);

        self::assertSame([
            [self::POSITION_DIRECTOR, PurchaseRoleCode::DIRECTOR->value],
            [self::POSITION_DEPARTMENT, PurchaseRoleCode::PURCHASE_DEPARTMENT->value],
            [self::POSITION_CHECKS, PurchaseRoleCode::ACCOUNTING->value],
            [self::POSITION_CHECKS, PurchaseRoleCode::LEGAL->value],
            [self::POSITION_DIRECTOR_FINAL, PurchaseRoleCode::DIRECTOR->value],
            [self::POSITION_FINANCE, PurchaseRoleCode::FINANCE_DIRECTOR->value],
        ], $this->shape($request));
        self::assertSame(self::POSITION_DIRECTOR, $request->getCurrentPosition());
    }

    /**
     * Позиция замов при подаче пустая: до решения директора неизвестно, кто они
     * и есть ли вообще. Место под них зарезервировано, шага нет.
     */
    public function testApproversSlotIsEmptyUntilDirectorDecides(): void
    {
        $service = $this->service();
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);

        $service->submit($request, $author);

        self::assertSame([], $this->stepsAt($request, self::POSITION_APPROVERS));
    }

    /** Бухгалтерия и юристы параллельны: позиция уходит, когда подписали оба. */
    public function testParallelPositionWaitsForBothSignatures(): void
    {
        $service = $this->service();
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);

        $service->submit($request, $author);
        $service->approveStep($request, $this->stepAt($request, self::POSITION_DIRECTOR), $this->user(2));
        $service->approveStep($request, $this->stepAt($request, self::POSITION_DEPARTMENT), $this->user(3));

        $service->approveStep(
            $request,
            $this->stepAt($request, self::POSITION_CHECKS, PurchaseRoleCode::ACCOUNTING),
            $this->user(4),
        );

        self::assertSame(self::POSITION_CHECKS, $request->getCurrentPosition());

        $service->approveStep(
            $request,
            $this->stepAt($request, self::POSITION_CHECKS, PurchaseRoleCode::LEGAL),
            $this->user(5),
        );

        self::assertSame(self::POSITION_DIRECTOR_FINAL, $request->getCurrentPosition());
    }

    /**
     * Исполнитель — тот, кто закрыл шаг отдела закупок: он вёл ресёрч и документы.
     * Не первый подписант конвейера и не тот, кто платит.
     */
    public function testExecutorIsTheOneWhoClosedDepartmentStep(): void
    {
        $service = $this->service();
        $author = $this->user(1);
        $director = $this->user(2);
        $buyer = $this->user(3);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);

        $service->submit($request, $author);
        $service->approveStep($request, $this->stepAt($request, self::POSITION_DIRECTOR), $director);

        self::assertNull($request->getExecutor(), 'подпись директора исполнителя не назначает');

        $service->approveStep($request, $this->stepAt($request, self::POSITION_DEPARTMENT), $buyer);

        self::assertSame($buyer, $request->getExecutor());
    }

    public function testApprovingOutOfTurnIsRejected(): void
    {
        $service = $this->service();
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);

        $service->submit($request, $author);

        $this->expectTransitionError(SpaApiError::PURCHASE_STEP_NOT_ACTIVE);
        $service->approveStep($request, $this->stepAt($request, self::POSITION_FINANCE), $this->user(9));
    }

    /**
     * Требование файла живёт на шаге, а не в конвейере: пока проверка нигде не
     * включена, но она работает — на неё будет опираться редактор шаблонов.
     */
    public function testStepRequiringFileBlocksApproval(): void
    {
        $service = $this->service();
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::FAST, $author);

        $service->submit($request, $author);
        $step = $this->stepAt($request, self::POSITION_FAST_DEPARTMENT);
        $step->setRequiresFileType(PurchaseFileType::CONTRACT);

        $this->expectTransitionError(SpaApiError::PURCHASE_STEP_FILE_REQUIRED);
        $service->approveStep($request, $step, $this->user(2));
    }

    // Решение директора

    /**
     * Замы встают на свою позицию — далеко позади отдела закупок, — а заявка
     * после подписи директора уезжает именно в закупки. Автор согласантом не
     * становится, повторы схлопываются.
     */
    public function testDirectorSendAssignsApproversToReservedPosition(): void
    {
        $service = $this->service();
        $author = $this->user(1);
        $director = $this->user(2);
        $deputy = $this->user(7);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);

        $service->submit($request, $author);
        $service->directorSend(
            $request,
            $this->stepAt($request, self::POSITION_DIRECTOR),
            $director,
            [],
            [$deputy, $deputy, $author],
        );

        $assigned = $this->stepsAt($request, self::POSITION_APPROVERS);
        self::assertCount(1, $assigned, 'автор и дубликаты в согласанты не попадают');
        self::assertSame($deputy, $assigned[0]->getApproverUser());
        self::assertSame(self::POSITION_DEPARTMENT, $request->getCurrentPosition());
    }

    /** С итогового решения замов назначить нельзя: их позиция уже позади. */
    public function testApproversCannotBeAssignedFromFinalDirectorStep(): void
    {
        $service = $this->service();
        $author = $this->user(1);
        $director = $this->user(2);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);

        $service->submit($request, $author);
        $service->approveStep($request, $this->stepAt($request, self::POSITION_DIRECTOR), $director);
        $service->approveStep($request, $this->stepAt($request, self::POSITION_DEPARTMENT), $this->user(3));
        $service->approveStep(
            $request,
            $this->stepAt($request, self::POSITION_CHECKS, PurchaseRoleCode::ACCOUNTING),
            $this->user(4),
        );
        $service->approveStep(
            $request,
            $this->stepAt($request, self::POSITION_CHECKS, PurchaseRoleCode::LEGAL),
            $this->user(5),
        );

        $this->expectTransitionError(SpaApiError::PURCHASE_INVALID_STATUS);
        $service->directorSend(
            $request,
            $this->stepAt($request, self::POSITION_DIRECTOR_FINAL),
            $director,
            [],
            [$this->user(7)],
        );
    }

    /** Снятая позиция в сумму не идёт, количество берётся утверждённое. */
    public function testDirectorEditsAffectTotalAmount(): void
    {
        $service = $this->service();
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author, ['100.00', '200.00']);

        $service->submit($request, $author);
        $service->directorSend(
            $request,
            $this->stepAt($request, self::POSITION_DIRECTOR),
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
    public function testDirectorCannotExcludeEveryItem(): void
    {
        $service = $this->service();
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);

        $service->submit($request, $author);

        $this->expectTransitionError(SpaApiError::PURCHASE_ITEMS_REQUIRED);
        $service->directorSend(
            $request,
            $this->stepAt($request, self::POSITION_DIRECTOR),
            $this->user(2),
            [1 => ['included' => false, 'quantity' => null]],
            [],
        );
    }

    // Возврат в отдел закупок

    /**
     * Бухгалтерия и юристы бракуют пакет документов, а не заявку: она остаётся
     * на согласовании и откатывается на шаг закупок. Подписи, успевшие лечь
     * после этого шага, сбрасываются — пакет будет другой.
     */
    public function testReturnToDepartmentResetsLaterSignatures(): void
    {
        $service = $this->service();
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);

        $service->submit($request, $author);
        $service->approveStep($request, $this->stepAt($request, self::POSITION_DIRECTOR), $this->user(2));
        $service->approveStep($request, $this->stepAt($request, self::POSITION_DEPARTMENT), $this->user(3));
        $service->approveStep(
            $request,
            $this->stepAt($request, self::POSITION_CHECKS, PurchaseRoleCode::ACCOUNTING),
            $this->user(4),
        );

        $service->returnToDepartment(
            $request,
            $this->stepAt($request, self::POSITION_CHECKS, PurchaseRoleCode::LEGAL),
            $this->user(5),
            'Договор без реквизитов',
        );

        self::assertSame(PurchaseStatus::ON_APPROVAL, $request->getStatus());
        self::assertSame(self::POSITION_DEPARTMENT, $request->getCurrentPosition());
        self::assertSame(
            PurchaseStepDecision::APPROVED,
            $this->stepAt($request, self::POSITION_DIRECTOR)->getDecision(),
            'подпись директора стоит раньше закупок и не сгорает',
        );
        self::assertSame(
            PurchaseStepDecision::PENDING,
            $this->stepAt($request, self::POSITION_CHECKS, PurchaseRoleCode::ACCOUNTING)->getDecision(),
            'подпись бухгалтерии легла после закупок и сброшена',
        );
    }

    public function testReturnToDepartmentRequiresComment(): void
    {
        $service = $this->service();
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);

        $service->submit($request, $author);
        $service->approveStep($request, $this->stepAt($request, self::POSITION_DIRECTOR), $this->user(2));
        $service->approveStep($request, $this->stepAt($request, self::POSITION_DEPARTMENT), $this->user(3));

        $this->expectTransitionError(SpaApiError::PURCHASE_COMMENT_REQUIRED);
        $service->returnToDepartment(
            $request,
            $this->stepAt($request, self::POSITION_CHECKS, PurchaseRoleCode::ACCOUNTING),
            $this->user(4),
            '   ',
        );
    }

    /** У быстрой заявки закупки первые — возвращать документы некуда. */
    public function testReturnToDepartmentImpossibleOnFastRoute(): void
    {
        $service = $this->service();
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::FAST, $author);

        $service->submit($request, $author);

        $this->expectTransitionError(SpaApiError::PURCHASE_STEP_NOT_FOUND);
        $service->returnToDepartment(
            $request,
            $this->stepAt($request, self::POSITION_FAST_DEPARTMENT),
            $this->user(2),
            'Не хочу',
        );
    }

    // Отказ, повторная подача, отзыв подписи

    public function testRejectSendsRequestBackToAuthor(): void
    {
        $service = $this->service();
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);

        $service->submit($request, $author);
        $step = $this->stepAt($request, self::POSITION_DIRECTOR);
        $service->rejectStep($request, $step, $this->user(2), 'Не сейчас');

        self::assertSame(PurchaseStatus::REJECTED, $request->getStatus());
        self::assertSame(PurchaseStepDecision::REJECTED, $step->getDecision());
        self::assertSame('Не сейчас', $step->getComment());
    }

    /** Повторная подача начинает согласование с нуля: состав и сумма могли измениться. */
    public function testResubmitRebuildsRouteFromScratch(): void
    {
        $service = $this->service();
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);

        $service->submit($request, $author);
        $service->rejectStep($request, $this->stepAt($request, self::POSITION_DIRECTOR), $this->user(2), 'Доработать');
        $service->submit($request, $author);

        self::assertSame(PurchaseStatus::ON_APPROVAL, $request->getStatus());
        self::assertCount(6, $request->getSteps());
        self::assertSame(self::POSITION_DIRECTOR, $request->getCurrentPosition());
        foreach ($request->getSteps() as $step) {
            self::assertTrue($step->isPending(), 'после пересборки все шаги ждут решения');
        }
    }

    /**
     * Персональный откат директора: его подпись закрывает позицию сразу, окна
     * «на передумать» не остаётся, а ошибиться тогглом легко. Сбрасывается его
     * шаг и всё, что успело подписаться после него.
     */
    public function testRevokeResetsOwnAndLaterSignatures(): void
    {
        $service = $this->service();
        $author = $this->user(1);
        $director = $this->user(2);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);

        $service->submit($request, $author);
        $service->approveStep($request, $this->stepAt($request, self::POSITION_DIRECTOR), $director);
        $service->approveStep($request, $this->stepAt($request, self::POSITION_DEPARTMENT), $this->user(3));
        $service->approveStep(
            $request,
            $this->stepAt($request, self::POSITION_CHECKS, PurchaseRoleCode::ACCOUNTING),
            $this->user(4),
        );
        $service->approveStep(
            $request,
            $this->stepAt($request, self::POSITION_CHECKS, PurchaseRoleCode::LEGAL),
            $this->user(5),
        );
        $finalStep = $this->stepAt($request, self::POSITION_DIRECTOR_FINAL);
        $service->approveStep($request, $finalStep, $director);
        $service->approveStep($request, $this->stepAt($request, self::POSITION_FINANCE), $this->user(6));

        self::assertSame(PurchaseStatus::APPROVED, $request->getStatus());

        $service->revokeStep($request, $finalStep, $director);

        self::assertSame(PurchaseStatus::ON_APPROVAL, $request->getStatus());
        self::assertSame(self::POSITION_DIRECTOR_FINAL, $request->getCurrentPosition());
        self::assertSame(
            PurchaseStepDecision::PENDING,
            $this->stepAt($request, self::POSITION_FINANCE)->getDecision(),
            'подпись финдиректора легла после отозванной и сброшена',
        );
    }

    /** Чужую подпись снимает только отзыв маршрута отделом закупок. */
    public function testForeignSignatureCannotBeRevoked(): void
    {
        $service = $this->service();
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);

        $service->submit($request, $author);
        $step = $this->stepAt($request, self::POSITION_DIRECTOR);
        $service->approveStep($request, $step, $this->user(2));

        $this->expectTransitionError(SpaApiError::PURCHASE_STEP_NOT_REVOKABLE);
        $service->revokeStep($request, $step, $this->user(3));
    }

    /**
     * В историю пишется каждое действие, а не только смена статуса: согласование
     * целиком идёт внутри ON_APPROVAL, а шаги при повторной подаче пересобираются.
     */
    public function testHistoryKeepsEveryAction(): void
    {
        $service = $this->service();
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::FAST, $author);

        $service->logCreated($request, $author);
        $service->submit($request, $author);
        $service->approveStep($request, $this->stepAt($request, self::POSITION_FAST_DEPARTMENT), $this->user(2), 'Куплено');

        $actions = array_map(
            static fn ($entry): ?string => $entry->getAction()?->value,
            $request->getHistory()->toArray(),
        );

        self::assertSame([
            PurchaseHistoryAction::CREATED->value,
            PurchaseHistoryAction::SUBMITTED->value,
            PurchaseHistoryAction::STEP_APPROVED->value,
            PurchaseHistoryAction::STATUS_CHANGED->value,
        ], array_values($actions));
    }

    // Обвязка

    /**
     * Настоящие сервисы на подменённой инфраструктуре: согласование не делает
     * запросов, а flush в мок-менеджере просто ничего не делает.
     */
    private function service(float $fastMaxAmount = 10_000.0): PurchaseRequestService
    {
        $em = $this->createMock(EntityManagerInterface::class);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static fn (object $message, array $stamps = []): Envelope => new Envelope($message),
        );

        $users = $this->createMock(UserRepository::class);
        $users->method('findByRoleName')->willReturn([]);

        $settings = $this->createMock(PurchaseSettingRepository::class);
        $settings->method('getFastMaxAmount')->willReturn($fastMaxAmount);

        // Носителей ролей в сценариях нет: маршрут адресует шаги ролям, а
        // подписывает кто угодно — сервис проверяет указатель, а не права.
        $approverRoles = $this->createMock(PurchaseApproverRoleRepository::class);
        $approverRoles->method('findRoleCodesForUser')->willReturn([]);
        $approverRoles->method('findUsersByRoleCodes')->willReturn([]);

        $roster = new PurchaseRoster($approverRoles);

        $templates = $this->createMock(PurchaseRouteTemplateRepository::class);
        $templates->method('findByKind')->willReturnCallback(
            fn (PurchaseRequestKind $kind): PurchaseRouteTemplate => $kind === PurchaseRequestKind::FAST
                ? $this->fastRoute()
                : $this->standardRoute(),
        );

        return new PurchaseRequestService(
            $em,
            new PurchaseNotificationPublisher(new NotificationPublisher($bus), $users, $roster),
            $settings,
            new ApprovalRouteBuilder($em, $templates),
        );
    }

    /** Быстрый маршрут: отдел закупок первый и единственный — дальше ведёт заявку сам. */
    private function fastRoute(): PurchaseRouteTemplate
    {
        return $this->route(PurchaseRequestKind::FAST, [
            $this->roleStep(
                self::POSITION_FAST_DEPARTMENT,
                PurchaseRoleCode::PURCHASE_DEPARTMENT,
                PurchaseStepPurpose::SOURCING,
            ),
        ]);
    }

    /**
     * Обычный маршрут: директор → отдел закупок → бухгалтерия и юристы →
     * профильные замы → директор повторно → финансовый директор.
     *
     * Порядок именно такой: отдел закупок делает ресёрч и готовит документы
     * РАНЬШЕ согласующих, потому что подписывать нечего, пока нет поставщика,
     * цен и договора. Итоговый шаг директора — тоже разбор: он смотрит
     * проверенный пакет и может урезать состав перед оплатой.
     */
    private function standardRoute(): PurchaseRouteTemplate
    {
        return $this->route(PurchaseRequestKind::STANDARD, [
            $this->roleStep(self::POSITION_DIRECTOR, PurchaseRoleCode::DIRECTOR, PurchaseStepPurpose::TRIAGE),
            $this->roleStep(
                self::POSITION_DEPARTMENT,
                PurchaseRoleCode::PURCHASE_DEPARTMENT,
                PurchaseStepPurpose::SOURCING,
            ),
            $this->roleStep(self::POSITION_CHECKS, PurchaseRoleCode::ACCOUNTING, PurchaseStepPurpose::SIGN_OFF),
            $this->roleStep(self::POSITION_CHECKS, PurchaseRoleCode::LEGAL, PurchaseStepPurpose::SIGN_OFF),
            (new PurchaseRouteTemplateStep())
                ->setPosition(self::POSITION_APPROVERS)
                ->setApproverKind(PurchaseApproverKind::USER)
                ->setPurpose(PurchaseStepPurpose::SIGN_OFF)
                ->setTitle('Профильные замы'),
            $this->roleStep(self::POSITION_DIRECTOR_FINAL, PurchaseRoleCode::DIRECTOR, PurchaseStepPurpose::TRIAGE),
            $this->roleStep(
                self::POSITION_FINANCE,
                PurchaseRoleCode::FINANCE_DIRECTOR,
                PurchaseStepPurpose::SIGN_OFF,
            ),
        ]);
    }

    /** @param list<PurchaseRouteTemplateStep> $steps */
    private function route(PurchaseRequestKind $kind, array $steps): PurchaseRouteTemplate
    {
        $template = (new PurchaseRouteTemplate())->setKind($kind);
        foreach ($steps as $step) {
            $template->addStep($step);
        }

        return $template;
    }

    private function roleStep(
        int $position,
        PurchaseRoleCode $code,
        PurchaseStepPurpose $purpose,
    ): PurchaseRouteTemplateStep {
        return (new PurchaseRouteTemplateStep())
            ->setPosition($position)
            ->setApproverKind(PurchaseApproverKind::ROLE)
            ->setRoleCode($code)
            ->setPurpose($purpose)
            ->setTitle($code->getLabel());
    }

    /**
     * Черновик с позициями по одной штуке: id проставляются вручную, потому что
     * правки состава директором приходят с фронта ключами по id позиции.
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

    private function user(int $id): User
    {
        $user = new User();
        self::setId($user, $id);

        return $user;
    }

    /**
     * Форма маршрута: позиция и код роли каждого шага в порядке создания.
     *
     * @return list<array{0: int, 1: string|null}>
     */
    private function shape(PurchaseRequest $request): array
    {
        $shape = [];
        foreach ($request->getSteps() as $step) {
            $shape[] = [$step->getPosition(), $step->getRoleCode()?->value];
        }

        return $shape;
    }

    /** Шаг позиции; при параллельных шагах нужна ещё и роль. */
    private function stepAt(PurchaseRequest $request, int $position, ?PurchaseRoleCode $code = null): PurchaseApprovalStep
    {
        foreach ($request->getSteps() as $step) {
            if ($step->getPosition() !== $position) {
                continue;
            }
            if ($code !== null && $step->getRoleCode() !== $code) {
                continue;
            }

            return $step;
        }

        self::fail(sprintf('В маршруте нет шага на позиции %d', $position));
    }

    /** @return list<PurchaseApprovalStep> */
    private function stepsAt(PurchaseRequest $request, int $position): array
    {
        $steps = [];
        foreach ($request->getSteps() as $step) {
            if ($step->getPosition() === $position) {
                $steps[] = $step;
            }
        }

        return $steps;
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
