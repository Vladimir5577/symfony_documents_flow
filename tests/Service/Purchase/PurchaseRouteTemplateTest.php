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
use App\Enum\Purchase\PurchaseRequestKind;
use App\Enum\Purchase\PurchaseRoleCode;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\Purchase\PurchaseStepPurpose;
use App\Repository\Purchase\PurchaseApproverRoleRepository;
use App\Repository\Purchase\PurchaseRouteTemplateRepository;
use App\Repository\Purchase\PurchaseSettingRepository;
use App\Repository\User\UserRepository;
use App\Service\Notification\NotificationPublisher;
use App\Service\Purchase\ApprovalRouteBuilder;
use App\Service\Purchase\ApprovalRouteEditor;
use App\Service\Purchase\PurchaseAccess;
use App\Service\Purchase\PurchaseNotificationPublisher;
use App\Service\Purchase\PurchaseRequestService;
use App\Service\Purchase\PurchaseRoster;
use App\Service\Purchase\PurchaseRouteException;
use App\Service\Purchase\PurchaseTransitionException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Настраиваемые маршруты: сборка заявки по заготовке из админки и правила,
 * без которых редактор молча ломает модуль.
 *
 * Регламент по умолчанию проверяет PurchaseApprovalFlowTest — здесь важно
 * обратное: что маршрут можно переставить, и что после перестановки логика
 * по-прежнему находит свои опорные шаги по назначению, а не по номеру позиции.
 */
final class PurchaseRouteTemplateTest extends TestCase
{
    // Сборка маршрута по заготовке

    /** Заготовка из админки вытесняет умолчание целиком, включая порядок ролей. */
    public function testRouteIsBuiltFromSavedTemplate(): void
    {
        $template = $this->template(PurchaseRequestKind::STANDARD, [
            $this->roleStep(1, PurchaseRoleCode::PURCHASE_DEPARTMENT, PurchaseStepPurpose::SOURCING),
            $this->roleStep(2, PurchaseRoleCode::ACCOUNTING, PurchaseStepPurpose::SIGN_OFF),
            $this->roleStep(2, PurchaseRoleCode::LEGAL, PurchaseStepPurpose::SIGN_OFF),
            $this->roleStep(3, PurchaseRoleCode::FINANCE_DIRECTOR, PurchaseStepPurpose::SIGN_OFF),
        ]);

        $request = $this->request(PurchaseRequestKind::STANDARD, $this->user(1));
        $this->builder($template)->build($request);

        self::assertSame([
            [1, PurchaseRoleCode::PURCHASE_DEPARTMENT->value],
            [2, PurchaseRoleCode::ACCOUNTING->value],
            [2, PurchaseRoleCode::LEGAL->value],
            [3, PurchaseRoleCode::FINANCE_DIRECTOR->value],
        ], $this->shape($request));
        self::assertNull($request->getApproversPosition(), 'слота замов в заготовке нет');
    }

    /**
     * Ненастроенный маршрут — отказ в подаче, а не «возьмём типовой».
     *
     * Умолчания в коде нет намеренно, поэтому важно, что заявка не уходит в
     * согласование молча и без шагов: такая заявка не стояла бы ни у кого и
     * висела бы на согласовании вечно.
     */
    public function testSubmitIsRejectedWhenRouteIsNotConfigured(): void
    {
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);

        try {
            $this->service(null)->submit($request, $author);
            self::fail('Подача без настроенного маршрута должна отклоняться');
        } catch (PurchaseTransitionException $exception) {
            self::assertSame(SpaApiError::PURCHASE_ROUTE_NOT_CONFIGURED, $exception->errorCode);
        }

        self::assertSame(PurchaseStatus::DRAFT, $request->getStatus(), 'заявка осталась черновиком');
        self::assertCount(0, $request->getSteps());
    }

    /** Заготовка есть, но шагов в ней нет — то же самое: маршрут не настроен. */
    public function testEmptyTemplateIsTreatedAsNotConfigured(): void
    {
        $request = $this->request(PurchaseRequestKind::STANDARD, $this->user(1));

        $this->expectException(PurchaseTransitionException::class);
        $this->expectExceptionMessage(SpaApiError::PURCHASE_ROUTE_NOT_CONFIGURED);
        $this->builder($this->template(PurchaseRequestKind::STANDARD, []))->build($request);
    }

    /** Превью в форме создания пустое — фронту этого хватит, чтобы не пускать в подачу. */
    public function testPreviewIsEmptyWhenRouteIsNotConfigured(): void
    {
        self::assertSame([], $this->builder(null)->preview(PurchaseRequestKind::STANDARD));
    }

    /**
     * Слот замов шагом не становится: подписантов назначает директор, и до его
     * решения закрыть такой шаг было бы некому — маршрут встал бы насмерть.
     */
    public function testApproversSlotReservesPositionWithoutStep(): void
    {
        $template = $this->template(PurchaseRequestKind::STANDARD, [
            $this->roleStep(1, PurchaseRoleCode::DIRECTOR, PurchaseStepPurpose::TRIAGE),
            $this->roleStep(2, PurchaseRoleCode::PURCHASE_DEPARTMENT, PurchaseStepPurpose::SOURCING),
            $this->slot(3),
        ]);

        $request = $this->request(PurchaseRequestKind::STANDARD, $this->user(1));
        $this->builder($template)->build($request);

        self::assertCount(2, $request->getSteps());
        self::assertSame(3, $request->getApproversPosition());
    }

    /**
     * Исполнитель и возврат документов ищут шаг ресёрча по назначению: роль на
     * нём может быть любой, и переставленный маршрут это не ломает.
     */
    public function testSourcingStepKeepsItsMeaningAfterReorder(): void
    {
        $template = $this->template(PurchaseRequestKind::STANDARD, [
            $this->roleStep(1, PurchaseRoleCode::ACCOUNTING, PurchaseStepPurpose::SIGN_OFF),
            $this->roleStep(2, PurchaseRoleCode::FINANCE_DIRECTOR, PurchaseStepPurpose::SOURCING),
            $this->roleStep(3, PurchaseRoleCode::DIRECTOR, PurchaseStepPurpose::SIGN_OFF),
        ]);

        $service = $this->service($template);
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);
        $buyer = $this->user(3);

        $service->submit($request, $author);
        $service->approveStep($request, $this->stepAt($request, 1), $this->user(2));
        $service->approveStep($request, $this->stepAt($request, 2), $buyer);

        self::assertSame($buyer, $request->getExecutor(), 'исполнитель — закрывший ресёрч, кем бы он ни был');

        $service->returnToDepartment($request, $this->stepAt($request, 3), $this->user(4), 'Нет счёта');

        self::assertSame(2, $request->getCurrentPosition(), 'документы вернулись на шаг ресёрча');
    }

    /** Маршрут без слота замов: назначать их некуда, и молча терять нельзя. */
    public function testApproversCannotBeAssignedWithoutSlot(): void
    {
        $template = $this->template(PurchaseRequestKind::STANDARD, [
            $this->roleStep(1, PurchaseRoleCode::DIRECTOR, PurchaseStepPurpose::TRIAGE),
            $this->roleStep(2, PurchaseRoleCode::PURCHASE_DEPARTMENT, PurchaseStepPurpose::SOURCING),
        ]);

        $service = $this->service($template);
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);

        $service->submit($request, $author);

        $this->expectException(PurchaseTransitionException::class);
        $this->expectExceptionMessage(SpaApiError::PURCHASE_INVALID_STATUS);
        $service->directorSend($request, $this->stepAt($request, 1), $this->user(2), [], [$this->user(7)]);
    }

    /**
     * Кнопка «отметить замов» в разборе спрашивает то же, что проверяет сервис:
     * маршрут без слота её не даёт. Иначе директор отметил бы людей, а заявка
     * их не приняла — по шагам заявки слота не видно, он в шаг не превращается.
     */
    public function testAssigningApproversIsOfferedOnlyWhenRouteHasSlot(): void
    {
        $triageAndSourcing = [
            $this->roleStep(1, PurchaseRoleCode::DIRECTOR, PurchaseStepPurpose::TRIAGE),
            $this->roleStep(2, PurchaseRoleCode::PURCHASE_DEPARTMENT, PurchaseStepPurpose::SOURCING),
        ];

        $author = $this->user(1);
        $director = $this->user(2);
        $access = $this->accessOf(PurchaseRoleCode::DIRECTOR);

        $withSlot = $this->request(PurchaseRequestKind::STANDARD, $author);
        $this->service($this->template(
            PurchaseRequestKind::STANDARD,
            [...$triageAndSourcing, $this->slot(3)],
        ))->submit($withSlot, $author);

        $withoutSlot = $this->request(PurchaseRequestKind::STANDARD, $author);
        $this->service($this->template(PurchaseRequestKind::STANDARD, $triageAndSourcing))
            ->submit($withoutSlot, $author);

        self::assertTrue($access->canAssignApprovers($withSlot, $director));
        self::assertFalse($access->canAssignApprovers($withoutSlot, $director));
    }

    /** Параллельные шаги в превью склеиваются в одну строку. */
    public function testPreviewJoinsParallelSteps(): void
    {
        $template = $this->template(PurchaseRequestKind::FAST, [
            $this->roleStep(1, PurchaseRoleCode::PURCHASE_DEPARTMENT, PurchaseStepPurpose::SOURCING),
            $this->roleStep(2, PurchaseRoleCode::ACCOUNTING, PurchaseStepPurpose::SIGN_OFF),
            $this->roleStep(2, PurchaseRoleCode::LEGAL, PurchaseStepPurpose::SIGN_OFF),
        ]);

        self::assertSame([
            ['position' => 1, 'title' => 'Отдел закупок'],
            ['position' => 2, 'title' => 'Бухгалтерия и Юристы'],
        ], $this->builder($template)->preview(PurchaseRequestKind::FAST));
    }

    // Редактор заготовки

    public function testEditorReplacesStepsAndNormalizesPositions(): void
    {
        $template = $this->template(PurchaseRequestKind::STANDARD, [
            $this->roleStep(1, PurchaseRoleCode::DIRECTOR, PurchaseStepPurpose::TRIAGE),
        ]);

        $this->editor($template)->replace(PurchaseRequestKind::STANDARD, [
            ['position' => 30, 'roleCode' => 'FINANCE_DIRECTOR', 'purpose' => 'SIGN_OFF'],
            ['position' => 10, 'roleCode' => 'PURCHASE_DEPARTMENT', 'purpose' => 'SOURCING'],
            ['position' => 20, 'roleCode' => 'ACCOUNTING', 'purpose' => 'SIGN_OFF'],
            ['position' => 20, 'roleCode' => 'LEGAL', 'purpose' => 'SIGN_OFF'],
        ], $this->user(1));

        $shape = [];
        foreach ($template->getSteps() as $step) {
            $shape[] = [$step->getPosition(), $step->getRoleCode()?->value];
        }

        self::assertSame([
            [1, PurchaseRoleCode::PURCHASE_DEPARTMENT->value],
            [2, PurchaseRoleCode::ACCOUNTING->value],
            [2, PurchaseRoleCode::LEGAL->value],
            [3, PurchaseRoleCode::FINANCE_DIRECTOR->value],
        ], $shape, 'номера сжались в 1..N, параллельная группа осталась группой');
    }

    /** Заголовок необязателен: пустой подставляется из названия роли. */
    public function testEditorFallsBackToRoleNameAsTitle(): void
    {
        $template = $this->template(PurchaseRequestKind::FAST, []);

        $this->editor($template)->replace(PurchaseRequestKind::FAST, [
            ['position' => 1, 'roleCode' => 'PURCHASE_DEPARTMENT', 'purpose' => 'SOURCING', 'title' => '  '],
        ], $this->user(1));

        $step = $template->getSteps()->first();
        self::assertInstanceOf(PurchaseRouteTemplateStep::class, $step);
        self::assertNull($step->getTitle());
        self::assertSame('Отдел закупок', $step->resolveTitle());
    }

    public function testEditorRemembersWhoChangedTheRoute(): void
    {
        $template = $this->template(PurchaseRequestKind::FAST, []);
        $admin = $this->user(42);

        $this->editor($template)->replace(PurchaseRequestKind::FAST, [
            ['position' => 1, 'roleCode' => 'PURCHASE_DEPARTMENT', 'purpose' => 'SOURCING'],
        ], $admin);

        self::assertSame($admin, $template->getUpdatedBy());
        self::assertNotNull($template->getUpdatedAt());
        self::assertCount(1, $template->getSteps());
    }

    /** Пустой маршрут не сохраняется: для возврата к регламенту есть сброс. */
    public function testEditorRejectsEmptyRoute(): void
    {
        $this->expectRouteError(SpaApiError::PURCHASE_ROUTE_EMPTY);
        $this->editor($this->template(PurchaseRequestKind::FAST, []))
            ->replace(PurchaseRequestKind::FAST, [], $this->user(1));
    }

    /**
     * Маршрут из одних подписей сохраняется: шаг ресёрча необязателен.
     *
     * Такой маршрут для заявки, где закупка уже определена и нужны только визы.
     * Модуль это переживает — проверяем, что заявка проходит согласование до
     * конца, просто исполнителя у неё нет: поставщика на таком маршруте никто
     * не искал.
     */
    public function testRouteOfPureSignaturesWorksWithoutSourcing(): void
    {
        $template = $this->template(PurchaseRequestKind::FAST, []);
        $this->editor($template)->replace(PurchaseRequestKind::FAST, [
            ['position' => 1, 'roleCode' => 'DIRECTOR', 'purpose' => 'TRIAGE'],
            ['position' => 2, 'roleCode' => 'FINANCE_DIRECTOR', 'purpose' => 'SIGN_OFF'],
        ], $this->user(1));

        $author = $this->user(2);
        $request = $this->request(PurchaseRequestKind::FAST, $author);
        $service = $this->service($template);

        $service->submit($request, $author);
        $service->approveStep($request, $this->stepAt($request, 1), $this->user(3));
        $service->approveStep($request, $this->stepAt($request, 2), $this->user(4));

        self::assertSame(PurchaseStatus::APPROVED, $request->getStatus());
        self::assertNull($request->getExecutor(), 'исполнителя даёт шаг ресёрча, а его в маршруте нет');
    }

    /** Слот замов позади разбора: назначать их директору иначе некуда. */
    public function testEditorRejectsApproversSlotBeforeTriage(): void
    {
        $this->expectRouteError(SpaApiError::PURCHASE_ROUTE_APPROVERS_SLOT_INVALID);
        $this->editor($this->template(PurchaseRequestKind::STANDARD, []))->replace(PurchaseRequestKind::STANDARD, [
            ['position' => 1, 'approverKind' => 'USER'],
            ['position' => 2, 'roleCode' => 'PURCHASE_DEPARTMENT', 'purpose' => 'SOURCING'],
            ['position' => 3, 'roleCode' => 'DIRECTOR', 'purpose' => 'TRIAGE'],
        ], $this->user(1));
    }

    public function testEditorRejectsTwoApproversSlots(): void
    {
        $this->expectRouteError(SpaApiError::PURCHASE_ROUTE_APPROVERS_SLOT_INVALID);
        $this->editor($this->template(PurchaseRequestKind::STANDARD, []))->replace(PurchaseRequestKind::STANDARD, [
            ['position' => 1, 'roleCode' => 'DIRECTOR', 'purpose' => 'TRIAGE'],
            ['position' => 2, 'roleCode' => 'PURCHASE_DEPARTMENT', 'purpose' => 'SOURCING'],
            ['position' => 3, 'approverKind' => 'USER'],
            ['position' => 4, 'approverKind' => 'USER'],
        ], $this->user(1));
    }

    public function testEditorRejectsUnknownRole(): void
    {
        $this->expectRouteError(SpaApiError::PURCHASE_ROUTE_STEP_INVALID);
        $this->editor($this->template(PurchaseRequestKind::FAST, []))->replace(PurchaseRequestKind::FAST, [
            ['position' => 1, 'roleCode' => 'CHIEF_ENGINEER', 'purpose' => 'SOURCING'],
        ], $this->user(1));
    }

    /**
     * Ролью зама шаг не адресуется: такой шаг закрыл бы любой зам, а замов
     * подбирают под заявку поимённо — для этого в маршруте ставят слот.
     */
    public function testEditorRejectsDeputyRoleOnStep(): void
    {
        $this->expectRouteError(SpaApiError::PURCHASE_ROUTE_STEP_INVALID);
        $this->editor($this->template(PurchaseRequestKind::FAST, []))->replace(PurchaseRequestKind::FAST, [
            ['position' => 1, 'roleCode' => PurchaseRoleCode::PROFILE_DEPUTY->value, 'purpose' => 'SIGN_OFF'],
        ], $this->user(1));
    }

    /** В слот замов идут только те, кому роль выдали в админке. */
    public function testOnlyDeputyRoleHoldersCanBeAssignedToSlot(): void
    {
        $deputy = $this->user(2);
        $accountant = $this->user(3);

        self::assertTrue($this->accessOf(PurchaseRoleCode::PROFILE_DEPUTY)->canBeProfileDeputy($deputy));
        self::assertFalse($this->accessOf(PurchaseRoleCode::ACCOUNTING)->canBeProfileDeputy($accountant));
    }

    // Обвязка

    private function builder(?PurchaseRouteTemplate $template): ApprovalRouteBuilder
    {
        return new ApprovalRouteBuilder($this->em(), $this->templates($template));
    }

    private function editor(PurchaseRouteTemplate $template): ApprovalRouteEditor
    {
        $templates = $this->templates($template);
        $templates->method('getOrCreate')->willReturn($template);

        return new ApprovalRouteEditor($this->em(), $templates);
    }

    private function service(?PurchaseRouteTemplate $template): PurchaseRequestService
    {
        $em = $this->em();

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static fn (object $message, array $stamps = []): Envelope => new Envelope($message),
        );

        $users = $this->createMock(UserRepository::class);
        $users->method('findByRoleName')->willReturn([]);

        $settings = $this->createMock(PurchaseSettingRepository::class);
        $settings->method('getFastMaxAmount')->willReturn(10_000.0);

        $approverRoles = $this->createMock(PurchaseApproverRoleRepository::class);
        $approverRoles->method('findRoleCodesForUser')->willReturn([]);
        $approverRoles->method('findUsersByRoleCodes')->willReturn([]);

        $roster = new PurchaseRoster($approverRoles);

        return new PurchaseRequestService(
            $em,
            new PurchaseNotificationPublisher(new NotificationPublisher($bus), $users, $roster),
            $settings,
            new ApprovalRouteBuilder($em, $this->templates($template)),
        );
    }

    /** Права на заявку от лица носителя этих ролей модуля. */
    private function accessOf(PurchaseRoleCode ...$codes): PurchaseAccess
    {
        $approverRoles = $this->createMock(PurchaseApproverRoleRepository::class);
        $approverRoles->method('findRoleCodesForUser')->willReturn(array_values($codes));

        return new PurchaseAccess(new PurchaseRoster($approverRoles));
    }

    private function em(): EntityManagerInterface
    {
        return $this->createMock(EntityManagerInterface::class);
    }

    /** @return PurchaseRouteTemplateRepository&\PHPUnit\Framework\MockObject\MockObject */
    private function templates(?PurchaseRouteTemplate $template): PurchaseRouteTemplateRepository
    {
        $repo = $this->createMock(PurchaseRouteTemplateRepository::class);
        $repo->method('findByKind')->willReturn($template);

        return $repo;
    }

    /** @param list<PurchaseRouteTemplateStep> $steps */
    private function template(PurchaseRequestKind $kind, array $steps): PurchaseRouteTemplate
    {
        $template = (new PurchaseRouteTemplate())->setKind($kind);
        foreach ($steps as $step) {
            $template->addStep($step);
        }

        return $template;
    }

    private function roleStep(int $position, PurchaseRoleCode $code, PurchaseStepPurpose $purpose): PurchaseRouteTemplateStep
    {
        return (new PurchaseRouteTemplateStep())
            ->setPosition($position)
            ->setApproverKind(PurchaseApproverKind::ROLE)
            ->setRoleCode($code)
            ->setPurpose($purpose);
    }

    private function slot(int $position): PurchaseRouteTemplateStep
    {
        return (new PurchaseRouteTemplateStep())
            ->setPosition($position)
            ->setApproverKind(PurchaseApproverKind::USER)
            ->setPurpose(PurchaseStepPurpose::SIGN_OFF);
    }

    private function request(PurchaseRequestKind $kind, User $author): PurchaseRequest
    {
        $request = new PurchaseRequest();
        $request->setTitle('Картриджи для принтера')
            ->setCreatedAs($kind)
            ->setCreatedBy($author);
        self::setId($request, 500);

        $item = (new PurchaseRequestItem())
            ->setName('Позиция 1')
            ->setUnit('шт')
            ->setQuantity('1.000')
            ->setEstimatedPrice('100.00')
            ->setPosition(1);
        self::setId($item, 1);
        $request->addItem($item);

        return $request;
    }

    private function user(int $id): User
    {
        $user = new User();
        self::setId($user, $id);

        return $user;
    }

    /**
     * Форма маршрута: позиция и код роли каждого шага.
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

    private function stepAt(PurchaseRequest $request, int $position): PurchaseApprovalStep
    {
        foreach ($request->getSteps() as $step) {
            if ($step->getPosition() === $position) {
                return $step;
            }
        }

        self::fail(sprintf('В маршруте нет шага на позиции %d', $position));
    }

    private function expectRouteError(string $errorCode): void
    {
        $this->expectException(PurchaseRouteException::class);
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
