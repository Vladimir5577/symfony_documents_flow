<?php

declare(strict_types=1);

namespace App\Tests\Service\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseApprovalTask;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestItem;
use App\Entity\Purchase\PurchaseRouteDefault;
use App\Entity\Purchase\PurchaseRouteTemplate;
use App\Entity\Purchase\PurchaseRouteTemplateStage;
use App\Entity\Purchase\PurchaseRouteTemplateTask;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseRequestKind;
use App\Enum\Purchase\PurchaseRoleCode;
use App\Enum\Purchase\PurchaseStagePurpose;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\Purchase\PurchaseTaskAssignment;
use App\Repository\Purchase\PurchaseApproverRoleRepository;
use App\Repository\Purchase\PurchaseRouteDefaultRepository;
use App\Repository\Purchase\PurchaseRouteTemplateRepository;
use App\Repository\User\UserRepository;
use App\Service\Notification\NotificationPublisher;
use App\Service\Purchase\ApprovalRouteBuilder;
use App\Service\Purchase\ApprovalRouteEditor;
use App\Service\Purchase\ApprovalRouteResolver;
use App\Service\Purchase\PurchaseAccess;
use App\Service\Purchase\PurchaseApprovalWorkflow;
use App\Service\Purchase\PurchaseHistoryLogger;
use App\Service\Purchase\PurchaseNotificationPublisher;
use App\Service\Purchase\PurchaseRequestEditor;
use App\Service\Purchase\PurchaseRoster;
use App\Service\Purchase\PurchaseRouteException;
use App\Service\Purchase\PurchaseTransitionException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Настраиваемые маршруты: выбор заготовки под заявку, сборка снимка и правила
 * редактора, без которых админка молча ломает модуль.
 *
 * Регламент целиком проверяет PurchaseApprovalFlowTest — здесь важно обратное:
 * что маршрут можно переставить и завести второй, и что после перестановки логика
 * по-прежнему находит свои опорные этапы по назначению, а не по номеру позиции.
 *
 * Каждое правило редактора закрывает конкретную поломку. Она названа в докблоке
 * теста: правило без такой причины — это правило, которое однажды запретит
 * законный регламент.
 */
final class PurchaseRouteTemplateTest extends TestCase
{
    // Выбор заготовки под заявку

    /** Назначенный заявке маршрут сильнее дефолта: его выбрали для неё. */
    public function testAssignedTemplateWinsOverDefault(): void
    {
        $assigned = $this->template('SECURITY', [$this->stage(PurchaseStagePurpose::SOURCING, [
            $this->roleTask(PurchaseRoleCode::LEGAL),
        ])]);

        $request = $this->request(PurchaseRequestKind::STANDARD, $this->user(1));
        $request->setRouteTemplate($assigned);

        self::assertSame($assigned, $this->resolver($this->defaultTemplate())->resolve($request));
    }

    public function testDefaultTemplateIsUsedWhenNoneAssigned(): void
    {
        $default = $this->defaultTemplate();
        $request = $this->request(PurchaseRequestKind::STANDARD, $this->user(1));

        self::assertSame($default, $this->resolver($default)->resolve($request));
    }

    /**
     * Ненастроенный маршрут — отказ в подаче, а не «возьмём типовой».
     *
     * Умолчания в коде нет намеренно, поэтому важно, что заявка не уходит в
     * согласование молча и без этапов: такая заявка не стояла бы ни у кого и
     * висела бы на согласовании вечно.
     */
    public function testSubmitIsRejectedWhenRouteIsNotConfigured(): void
    {
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);

        try {
            $this->workflow(null)->submit($request, $author);
            self::fail('Подача без настроенного маршрута должна отклоняться');
        } catch (PurchaseTransitionException $exception) {
            self::assertSame(SpaApiError::PURCHASE_ROUTE_NOT_CONFIGURED, $exception->errorCode);
        }

        self::assertSame(PurchaseStatus::DRAFT, $request->getStatus(), 'заявка осталась черновиком');
        self::assertCount(0, $request->getStages());
    }

    /** Заготовка есть, но этапов в ней нет — то же самое: маршрут не настроен. */
    public function testEmptyTemplateIsTreatedAsNotConfigured(): void
    {
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);

        $this->expectTransitionError(SpaApiError::PURCHASE_ROUTE_NOT_CONFIGURED);
        $this->workflow($this->template('EMPTY', []))->submit($request, $author);
    }

    /**
     * Дефолт мог быть назначен, а заготовку потом выключили. Молча подменять её
     * другой нельзя: «как согласуют закупки» — не то решение, которое сервер
     * принимает за админа.
     */
    public function testDeactivatedTemplateIsNotUsedSilently(): void
    {
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);

        $this->expectTransitionError(SpaApiError::PURCHASE_ROUTE_NOT_CONFIGURED);
        $this->workflow($this->defaultTemplate()->setActive(false))->submit($request, $author);
    }

    /** Маршрут, не разрешённый этому виду заявки, к ней не применяется. */
    public function testTemplateNotAllowingKindIsRejected(): void
    {
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);

        $this->expectTransitionError(SpaApiError::PURCHASE_ROUTE_NOT_CONFIGURED);
        $this->workflow($this->defaultTemplate()->setAllowedKinds([PurchaseRequestKind::FAST]))
            ->submit($request, $author);
    }

    // Сборка снимка

    /** Снимок повторяет заготовку целиком, включая порядок и состав этапов. */
    public function testSnapshotRepeatsTemplate(): void
    {
        $template = $this->template('CUSTOM', [
            $this->stage(PurchaseStagePurpose::SOURCING, [$this->roleTask(PurchaseRoleCode::PURCHASE_DEPARTMENT)]),
            $this->stage(PurchaseStagePurpose::SIGN_OFF, [
                $this->roleTask(PurchaseRoleCode::ACCOUNTING),
                $this->roleTask(PurchaseRoleCode::LEGAL),
            ]),
            $this->stage(PurchaseStagePurpose::SIGN_OFF, [$this->roleTask(PurchaseRoleCode::FINANCE_DIRECTOR)]),
        ]);

        $request = $this->request(PurchaseRequestKind::STANDARD, $this->user(1));
        $this->builder()->build($request, $template);

        self::assertSame([
            [1, [PurchaseRoleCode::PURCHASE_DEPARTMENT->value]],
            [2, [PurchaseRoleCode::ACCOUNTING->value, PurchaseRoleCode::LEGAL->value]],
            [3, [PurchaseRoleCode::FINANCE_DIRECTOR->value]],
        ], $this->shape($request));
        self::assertSame('Тестовый маршрут', $request->getAppliedRouteTemplateName(), 'снимок помнит заготовку');
    }

    /**
     * Правка заготовки не трогает заявки в пути: снимок и есть версия регламента,
     * по которой заявка пошла. Версий заготовке поэтому не нужно.
     */
    public function testTemplateEditDoesNotTouchRequestsInFlight(): void
    {
        $template = $this->defaultTemplate();
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);

        $this->workflow($template)->submit($request, $author);
        $before = $this->shape($request);

        $this->editor($template)->update($template, $this->payload([
            ['purpose' => 'SOURCING', 'tasks' => [['roleCode' => 'PURCHASE_DEPARTMENT']]],
        ]), $this->user(42));

        self::assertSame($before, $this->shape($request));
    }

    /**
     * Исполнитель и возврат документов ищут этап ресёрча по назначению: роль на
     * нём может быть любой, и переставленный маршрут это не ломает.
     */
    public function testSourcingStageKeepsItsMeaningAfterReorder(): void
    {
        $template = $this->template('REORDERED', [
            $this->stage(PurchaseStagePurpose::SIGN_OFF, [$this->roleTask(PurchaseRoleCode::ACCOUNTING)]),
            $this->stage(PurchaseStagePurpose::SOURCING, [$this->roleTask(PurchaseRoleCode::FINANCE_DIRECTOR)]),
            $this->stage(PurchaseStagePurpose::SIGN_OFF, [$this->roleTask(PurchaseRoleCode::DIRECTOR)]),
        ]);

        $workflow = $this->workflow($template);
        $author = $this->user(1);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);
        $buyer = $this->user(3);

        $workflow->submit($request, $author);
        $workflow->approveTask($request, $this->taskAt($request, 1), $this->user(2));
        $workflow->approveTask($request, $this->taskAt($request, 2), $buyer);

        self::assertSame($buyer, $request->getExecutor(), 'исполнитель — закрывший ресёрч, кем бы он ни был');

        $workflow->returnToSourcing($request, $this->taskAt($request, 3), $this->user(4), 'Нет счёта');

        self::assertSame(2, $request->getCurrentStage()?->getPosition(), 'документы вернулись на ресёрч');
    }

    /**
     * Маршрут из одних подписей законен: этап ресёрча необязателен.
     *
     * Такой маршрут для заявки, где закупка уже определена и нужны только визы.
     * Модуль это переживает — заявка проходит согласование до конца, просто
     * исполнителя у неё нет: поставщика на таком маршруте никто не искал.
     */
    public function testRouteOfPureSignaturesWorksWithoutSourcing(): void
    {
        $template = $this->template('SIGNATURES', [
            $this->stage(PurchaseStagePurpose::TRIAGE, [$this->roleTask(PurchaseRoleCode::DIRECTOR)]),
            $this->stage(PurchaseStagePurpose::SIGN_OFF, [$this->roleTask(PurchaseRoleCode::FINANCE_DIRECTOR)]),
        ]);

        $workflow = $this->workflow($template);
        $author = $this->user(2);
        $request = $this->request(PurchaseRequestKind::STANDARD, $author);

        $workflow->submit($request, $author);
        $workflow->approveTask($request, $this->taskAt($request, 1), $this->user(3));
        $workflow->approveTask($request, $this->taskAt($request, 2), $this->user(4));

        self::assertSame(PurchaseStatus::APPROVED, $request->getStatus());
        self::assertNull($request->getExecutor(), 'исполнителя даёт ресёрч, а его в маршруте нет');
    }

    /** Превью склеивает адресатов этапа и помечает динамический этап. */
    public function testPreviewShowsStagesWithDynamicMarked(): void
    {
        $template = $this->template('PREVIEW', [
            $this->stage(PurchaseStagePurpose::TRIAGE, [$this->roleTask(PurchaseRoleCode::DIRECTOR)]),
            $this->stage(PurchaseStagePurpose::SIGN_OFF, [
                $this->roleTask(PurchaseRoleCode::ACCOUNTING),
                $this->roleTask(PurchaseRoleCode::LEGAL),
            ]),
            $this->stage(PurchaseStagePurpose::SIGN_OFF, [$this->dynamicTask(PurchaseRoleCode::PROFILE_DEPUTY)]),
        ]);

        self::assertSame([
            ['position' => 1, 'title' => 'Директор', 'purpose' => 'TRIAGE', 'dynamic' => false],
            ['position' => 2, 'title' => 'Бухгалтерия и Юристы', 'purpose' => 'SIGN_OFF', 'dynamic' => false],
            [
                'position' => 3,
                'title' => 'Профильный зам (назначает директор)',
                'purpose' => 'SIGN_OFF',
                'dynamic' => true,
            ],
        ], $this->builder()->preview($template));
    }

    // Редактор: структура

    /**
     * Порядок этапов задаёт порядок присланного списка, параллельность — состав
     * этапа. Номера считает бэк: прежде их присылал фронт, и параллельность
     * выражалась совпадением чисел, которое бэк восстанавливал обратно.
     */
    public function testEditorNumbersStagesAndTasksByOrder(): void
    {
        $template = $this->template('DRAFT', []);

        $this->editor($template)->update($template, $this->payload([
            ['purpose' => 'SOURCING', 'tasks' => [['roleCode' => 'PURCHASE_DEPARTMENT']]],
            ['purpose' => 'SIGN_OFF', 'tasks' => [['roleCode' => 'ACCOUNTING'], ['roleCode' => 'LEGAL']]],
            ['purpose' => 'SIGN_OFF', 'tasks' => [['roleCode' => 'FINANCE_DIRECTOR']]],
        ]), $this->user(1));

        $shape = [];
        foreach ($template->getStages() as $stage) {
            $tasks = [];
            foreach ($stage->getTasks() as $task) {
                $tasks[] = [$task->getPosition(), $task->getRoleCode()?->value];
            }
            $shape[] = [$stage->getPosition(), $tasks];
        }

        self::assertSame([
            [1, [[1, PurchaseRoleCode::PURCHASE_DEPARTMENT->value]]],
            [2, [[1, PurchaseRoleCode::ACCOUNTING->value], [2, PurchaseRoleCode::LEGAL->value]]],
            [3, [[1, PurchaseRoleCode::FINANCE_DIRECTOR->value]]],
        ], $shape);
    }

    /** Заголовок необязателен: пустой подставляется из названия роли. */
    public function testEditorFallsBackToRoleNameAsTitle(): void
    {
        $template = $this->template('DRAFT', []);

        $this->editor($template)->update($template, $this->payload([
            [
                'purpose' => 'SOURCING',
                'title' => '  ',
                'tasks' => [['roleCode' => 'PURCHASE_DEPARTMENT', 'title' => '  ']],
            ],
        ]), $this->user(1));

        $stage = $template->getStages()->first();
        self::assertInstanceOf(PurchaseRouteTemplateStage::class, $stage);
        self::assertNull($stage->getTitle());
        self::assertSame('Отдел закупок', $stage->resolveTitle());
    }

    public function testEditorRemembersWhoChangedTheRoute(): void
    {
        $template = $this->template('DRAFT', []);
        $admin = $this->user(42);

        $this->editor($template)->update($template, $this->payload([
            ['purpose' => 'SOURCING', 'tasks' => [['roleCode' => 'PURCHASE_DEPARTMENT']]],
        ]), $admin);

        self::assertSame($admin, $template->getUpdatedBy());
        self::assertNotNull($template->getUpdatedAt());
        self::assertCount(1, $template->getStages());
    }

    /** Отказ на исполнении смысла не имеет: деньги уже потрачены. */
    public function testExecutionStagesDisallowRejectByDefault(): void
    {
        $template = $this->template('DRAFT', []);

        $this->editor($template)->update($template, $this->payload([
            ['purpose' => 'SIGN_OFF', 'tasks' => [['roleCode' => 'FINANCE_DIRECTOR']]],
            ['purpose' => 'PAYMENT', 'tasks' => [['roleCode' => 'FINANCE_DIRECTOR']]],
            ['purpose' => 'DELIVERY', 'tasks' => [['assignmentType' => 'AUTHOR']]],
        ]), $this->user(1));

        $flags = [];
        foreach ($template->getStages() as $stage) {
            $flags[$stage->getPurpose()->value] = $stage->allowsReject();
        }

        self::assertSame(['SIGN_OFF' => true, 'PAYMENT' => false, 'DELIVERY' => false], $flags);
    }

    // Редактор: правила

    /** Пустой маршрут — не правка регламента, а его отмена. */
    public function testEditorRejectsEmptyRoute(): void
    {
        $this->expectRouteError(SpaApiError::PURCHASE_ROUTE_EMPTY);
        $this->editorUpdate([]);
    }

    /** Пустой этап — дырка в маршруте: заявка встанет на нём и никого не будет ждать. */
    public function testEditorRejectsStageWithoutTasks(): void
    {
        $this->expectRouteError(SpaApiError::PURCHASE_ROUTE_STAGE_INVALID);
        $this->editorUpdate([['purpose' => 'SIGN_OFF', 'tasks' => []]]);
    }

    /**
     * Разбор один: с него правят состав, выбирают согласантов и меняют маршрут, и
     * два разбора означали бы два места с этими правами.
     */
    public function testEditorRejectsTwoTriageStages(): void
    {
        $this->expectRouteError(SpaApiError::PURCHASE_ROUTE_STAGE_ORDER_INVALID);
        $this->editorUpdate([
            ['purpose' => 'TRIAGE', 'tasks' => [['roleCode' => 'DIRECTOR']]],
            ['purpose' => 'SOURCING', 'tasks' => [['roleCode' => 'PURCHASE_DEPARTMENT']]],
            ['purpose' => 'TRIAGE', 'tasks' => [['roleCode' => 'DIRECTOR']]],
        ]);
    }

    /**
     * Динамический этап только позже разбора: иначе выбирать на него людей
     * некому, и заявка встала бы, ожидая тех, кого никто не назначит.
     */
    public function testEditorRejectsDynamicStageBeforeTriage(): void
    {
        $this->expectRouteError(SpaApiError::PURCHASE_ROUTE_STAGE_ORDER_INVALID);
        $this->editorUpdate([
            ['purpose' => 'SIGN_OFF', 'tasks' => [$this->dynamicRow()]],
            ['purpose' => 'TRIAGE', 'tasks' => [['roleCode' => 'DIRECTOR']]],
        ]);
    }

    /** Маршрут вообще без разбора динамический этап тоже не тянет. */
    public function testEditorRejectsDynamicStageWithoutTriage(): void
    {
        $this->expectRouteError(SpaApiError::PURCHASE_ROUTE_STAGE_ORDER_INVALID);
        $this->editorUpdate([
            ['purpose' => 'SOURCING', 'tasks' => [['roleCode' => 'PURCHASE_DEPARTMENT']]],
            ['purpose' => 'SIGN_OFF', 'tasks' => [$this->dynamicRow()]],
        ]);
    }

    /**
     * Динамический этап заполняется целиком выбором разбирающего, поэтому ролевых
     * задач рядом быть не может: они остались бы ждать вместе с теми, кого ещё не
     * выбрали, и этап нельзя было бы ни закрыть, ни показать как «ожидает
     * назначения».
     */
    public function testEditorRejectsRoleTaskNextToDynamicTask(): void
    {
        $this->expectRouteError(SpaApiError::PURCHASE_ROUTE_STAGE_INVALID);
        $this->editorUpdate([
            ['purpose' => 'TRIAGE', 'tasks' => [['roleCode' => 'DIRECTOR']]],
            ['purpose' => 'SIGN_OFF', 'tasks' => [$this->dynamicRow(), ['roleCode' => 'LEGAL']]],
        ]);
    }

    /** Оплата перед подписью финдиректора — ошибка настройки, а не редкий регламент. */
    public function testEditorRejectsExecutionBeforeApproval(): void
    {
        $this->expectRouteError(SpaApiError::PURCHASE_ROUTE_STAGE_ORDER_INVALID);
        $this->editorUpdate([
            ['purpose' => 'PAYMENT', 'tasks' => [['roleCode' => 'FINANCE_DIRECTOR']]],
            ['purpose' => 'SIGN_OFF', 'tasks' => [['roleCode' => 'FINANCE_DIRECTOR']]],
        ]);
    }

    /** Задача заявителю на согласовании означала бы, что автор согласует сам себя. */
    public function testEditorRejectsAuthorTaskOnApprovalStage(): void
    {
        $this->expectRouteError(SpaApiError::PURCHASE_ROUTE_TASK_INVALID);
        $this->editorUpdate([['purpose' => 'SIGN_OFF', 'tasks' => [['assignmentType' => 'AUTHOR']]]]);
    }

    /** Заявитель на исполнении законен: товар приходит к нему. */
    public function testEditorAllowsAuthorTaskOnExecutionStage(): void
    {
        $template = $this->template('DRAFT', []);

        $this->editor($template)->update($template, $this->payload([
            ['purpose' => 'SIGN_OFF', 'tasks' => [['roleCode' => 'FINANCE_DIRECTOR']]],
            ['purpose' => 'DELIVERY', 'tasks' => [['assignmentType' => 'AUTHOR']]],
        ]), $this->user(1));

        self::assertCount(2, $template->getStages());
    }

    /**
     * Тело, которое присылает форма админки, целиком.
     *
     * Дословный снимок запроса из редактора: заголовки идут null, порядок этапов
     * форма собирает сама, а параллельные подписи приезжают задачами одного
     * этапа. Проверка контракта, а не правил: правила проверены выше поштучно, а
     * здесь важно, что настоящее тело формы сервер принимает без единой правки.
     */
    public function testEditorAcceptsPayloadFromAdminForm(): void
    {
        $template = $this->template('DRAFT', []);

        $this->editor($template)->update($template, $this->payload([
            [
                'purpose' => 'TRIAGE',
                'title' => null,
                'allowsReject' => true,
                'tasks' => [[
                    'assignmentType' => 'ROLE',
                    'roleCode' => 'DIRECTOR',
                    'title' => null,
                    'requiresFileType' => null,
                ]],
            ],
            [
                'purpose' => 'SOURCING',
                'title' => null,
                'allowsReject' => true,
                'tasks' => [[
                    'assignmentType' => 'ROLE',
                    'roleCode' => 'PURCHASE_DEPARTMENT',
                    'title' => null,
                    'requiresFileType' => null,
                ]],
            ],
            [
                'purpose' => 'SIGN_OFF',
                'title' => null,
                'allowsReject' => true,
                'tasks' => [[
                    'assignmentType' => 'DYNAMIC_USERS',
                    'candidateRoleCode' => 'PROFILE_DEPUTY',
                    'title' => null,
                    'requiresFileType' => null,
                ]],
            ],
            [
                'purpose' => 'SIGN_OFF',
                'title' => null,
                'allowsReject' => true,
                'tasks' => [
                    [
                        'assignmentType' => 'ROLE',
                        'roleCode' => 'LEGAL',
                        'title' => null,
                        'requiresFileType' => null,
                    ],
                    [
                        'assignmentType' => 'ROLE',
                        'roleCode' => 'ACCOUNTING',
                        'title' => null,
                        'requiresFileType' => null,
                    ],
                ],
            ],
            [
                'purpose' => 'PAYMENT',
                'title' => null,
                'allowsReject' => false,
                'tasks' => [[
                    'assignmentType' => 'ROLE',
                    'roleCode' => 'PURCHASE_DEPARTMENT',
                    'title' => null,
                    'requiresFileType' => null,
                ]],
            ],
            [
                'purpose' => 'DELIVERY',
                'title' => null,
                'allowsReject' => false,
                'tasks' => [[
                    'assignmentType' => 'AUTHOR',
                    'title' => null,
                    'requiresFileType' => null,
                ]],
            ],
            [
                'purpose' => 'CLOSING',
                'title' => null,
                'allowsReject' => false,
                'tasks' => [[
                    'assignmentType' => 'ROLE',
                    'roleCode' => 'PURCHASE_DEPARTMENT',
                    'title' => null,
                    'requiresFileType' => null,
                ]],
            ],
        ]), $this->user(1));

        $shape = [];
        foreach ($template->getStages() as $stage) {
            $shape[] = [$stage->getPurpose()->value, $stage->getTasks()->count()];
        }

        self::assertSame([
            ['TRIAGE', 1],
            ['SOURCING', 1],
            ['SIGN_OFF', 1],
            ['SIGN_OFF', 2],
            ['PAYMENT', 1],
            ['DELIVERY', 1],
            ['CLOSING', 1],
        ], $shape);
    }

    /**
     * Старые этапы должны дойти до базы удалёнными раньше, чем вставлены новые.
     *
     * У этапа уникальны (шаблон, позиция), а Doctrine всегда пишет INSERT раньше
     * DELETE. Пока замена шла одним flush, правка любого непустого маршрута
     * падала на уникальном ключе: новый этап на позиции 1 упирался в старый,
     * который ещё не удалили. Проверяем порядок вызовов, потому что заглушка в
     * базу не пишет и сама поломка здесь невидима.
     */
    public function testEditorDeletesOldStagesBeforeInsertingNew(): void
    {
        $template = $this->template('DRAFT', [
            $this->stage(PurchaseStagePurpose::TRIAGE, [$this->roleTask(PurchaseRoleCode::DIRECTOR)]),
        ]);

        $calls = [];
        $record = static function (string $call) use (&$calls): callable {
            return static function () use ($call, &$calls): void {
                $calls[] = $call;
            };
        };

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $work): mixed => $work($em),
        );
        $em->method('remove')->willReturnCallback($record('remove'));
        $em->method('persist')->willReturnCallback($record('persist'));
        $em->method('flush')->willReturnCallback($record('flush'));

        $templates = $this->createStub(PurchaseRouteTemplateRepository::class);
        $templates->method('findByCode')->willReturn($template);

        (new ApprovalRouteEditor($em, $templates, $this->createStub(PurchaseRouteDefaultRepository::class)))
            ->update($template, $this->payload([
                ['purpose' => 'SIGN_OFF', 'tasks' => [['roleCode' => 'LEGAL']]],
            ]), $this->user(1));

        self::assertSame(
            ['remove', 'flush', 'persist', 'persist', 'flush'],
            $calls,
            'между сносом старых этапов и вставкой новых обязана быть запись в базу',
        );
    }

    public function testEditorRejectsUnknownRole(): void
    {
        $this->expectRouteError(SpaApiError::PURCHASE_ROUTE_TASK_INVALID);
        $this->editorUpdate([['purpose' => 'SOURCING', 'tasks' => [['roleCode' => 'CHIEF_ENGINEER']]]]);
    }

    /**
     * Ролью зама задача не адресуется: её закрыл бы любой зам, а замов подбирают
     * под заявку поимённо — для этого этап делают динамическим.
     */
    public function testEditorRejectsDeputyRoleOnTask(): void
    {
        $this->expectRouteError(SpaApiError::PURCHASE_ROUTE_TASK_INVALID);
        $this->editorUpdate([[
            'purpose' => 'SIGN_OFF',
            'tasks' => [['roleCode' => PurchaseRoleCode::PROFILE_DEPUTY->value]],
        ]]);
    }

    /**
     * Сотрудников поимённо в заготовку не вписывают: маршрут переживает и отпуск,
     * и увольнение, а конкретные люди появляются только в снимке заявки.
     */
    public function testEditorRejectsNamedUserInTemplate(): void
    {
        $this->expectRouteError(SpaApiError::PURCHASE_ROUTE_TASK_INVALID);
        $this->editorUpdate([[
            'purpose' => 'SIGN_OFF',
            'tasks' => [['assignmentType' => PurchaseTaskAssignment::USER->value]],
        ]]);
    }

    public function testEditorRejectsUnknownFileRequirement(): void
    {
        $this->expectRouteError(SpaApiError::PURCHASE_ROUTE_TASK_INVALID);
        $this->editorUpdate([[
            'purpose' => 'CLOSING',
            'tasks' => [['roleCode' => 'PURCHASE_DEPARTMENT', 'requiresFileType' => 'BLUEPRINT']],
        ]]);
    }

    /** Требование файла живёт на задаче — перенести УПД на другой этап это правка в админке. */
    public function testEditorKeepsFileRequirementOnTask(): void
    {
        $template = $this->template('DRAFT', []);

        $this->editor($template)->update($template, $this->payload([
            [
                'purpose' => 'CLOSING',
                'tasks' => [['roleCode' => 'PURCHASE_DEPARTMENT', 'requiresFileType' => PurchaseFileType::UPD->value]],
            ],
        ]), $this->user(1));

        $stage = $template->getStages()->first();
        self::assertInstanceOf(PurchaseRouteTemplateStage::class, $stage);
        $task = $stage->getTasks()->first();
        self::assertInstanceOf(PurchaseRouteTemplateTask::class, $task);
        self::assertSame(PurchaseFileType::UPD, $task->getRequiresFileType());
    }

    // Редактор: жизненный цикл заготовки

    /** Код — ссылка на маршрут из фикстур и установки, поэтому он уникален. */
    public function testEditorRejectsTakenCode(): void
    {
        $taken = $this->template('STANDARD', []);
        $templates = $this->createStub(PurchaseRouteTemplateRepository::class);
        $templates->method('findByCode')->willReturn($taken);

        $this->expectRouteError(SpaApiError::PURCHASE_ROUTE_CODE_TAKEN);
        $this->editorWith($templates)->create([
            'code' => 'STANDARD',
            'name' => 'Ещё один',
            'allowedKinds' => ['STANDARD'],
            'stages' => [['purpose' => 'SIGN_OFF', 'tasks' => [['roleCode' => 'LEGAL']]]],
        ], $this->user(1));
    }

    /**
     * Заготовка без видов заявок не применима ни к чему, и отвечать на это надо
     * про шапку: жалоба на этап отправила бы искать ошибку в дереве, где её нет.
     */
    public function testEditorRejectsTemplateWithoutKinds(): void
    {
        $this->expectRouteError(SpaApiError::PURCHASE_ROUTE_META_INVALID);
        $this->editorWith($this->createStub(PurchaseRouteTemplateRepository::class))->create([
            'code' => 'NEW_ROUTE',
            'name' => 'Новый маршрут',
            'stages' => [['purpose' => 'SIGN_OFF', 'tasks' => [['roleCode' => 'LEGAL']]]],
        ], $this->user(1));
    }

    /** Созданная заготовка выключена по той же причине, что и копия. */
    public function testCreatedTemplateArrivesDisabled(): void
    {
        $created = $this->editorWith($this->createStub(PurchaseRouteTemplateRepository::class))->create([
            'code' => 'NEW_ROUTE',
            'name' => 'Новый маршрут',
            'allowedKinds' => [PurchaseRequestKind::STANDARD->value],
            'stages' => [['purpose' => 'TRIAGE', 'tasks' => [['roleCode' => 'DIRECTOR']]]],
        ], $this->user(1));

        self::assertFalse($created->isActive(), 'недособранный маршрут не должен попасть в выбор');
        self::assertSame('NEW_ROUTE', $created->getCode());
    }

    /**
     * Копия маршрута приходит выключенной: маршрут, появившийся в списке выбора
     * готовым к работе, — это регламент, который никто не просматривал.
     */
    public function testCloneCopiesStagesAndArrivesDisabled(): void
    {
        $source = $this->template('STANDARD', [
            $this->stage(PurchaseStagePurpose::TRIAGE, [$this->roleTask(PurchaseRoleCode::DIRECTOR)]),
            $this->stage(PurchaseStagePurpose::SIGN_OFF, [$this->dynamicTask(PurchaseRoleCode::PROFILE_DEPUTY)]),
        ]);

        $editor = $this->editorWith($this->createStub(PurchaseRouteTemplateRepository::class));
        $copy = $editor->clone($source, 'standard_v2', 'Обычный с охраной', $this->user(1));

        self::assertSame('STANDARD_V2', $copy->getCode(), 'код приводится к машинному виду');
        self::assertSame('Обычный с охраной', $copy->getName());
        self::assertFalse($copy->isActive());
        self::assertCount(2, $copy->getStages());
        self::assertSame(
            PurchaseRoleCode::PROFILE_DEPUTY,
            $copy->getStages()[1]->getCandidateRoleCode(),
            'пул динамического этапа скопирован',
        );
    }

    /**
     * Правку без версии не принимают: клиент, не сообщивший, что он видел, не
     * может показать, что не затирает чужой регламент.
     */
    public function testEditorRequiresVersionAdminSaw(): void
    {
        $template = $this->template('DRAFT', []);
        $payload = $this->payload([
            ['purpose' => 'SOURCING', 'tasks' => [['roleCode' => 'PURCHASE_DEPARTMENT']]],
        ]);
        unset($payload['version']);

        $this->expectRouteError(SpaApiError::PURCHASE_ROUTE_VERSION_REQUIRED);
        $this->editor($template)->update($template, $payload, $this->user(1));
    }

    /**
     * Второй админ, сохранивший ту же заготовку, получает отказ. Молча пропустить
     * его правку нельзя: дерево заменяется целиком, и первый потерял бы не поле, а
     * весь маршрут — а заявки пошли бы по регламенту, которого он не писал.
     */
    public function testEditorRefusesEditOfRouteChangedMeanwhile(): void
    {
        $template = $this->template('DRAFT', []);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('lock')->willThrowException(
            OptimisticLockException::lockFailedVersionMismatch($template, 1, 2),
        );

        $editor = new ApprovalRouteEditor(
            $em,
            $this->createStub(PurchaseRouteTemplateRepository::class),
            $this->createStub(PurchaseRouteDefaultRepository::class),
        );

        $this->expectRouteError(SpaApiError::PURCHASE_CONCURRENT_UPDATE);
        $editor->update($template, $this->payload([
            ['purpose' => 'SOURCING', 'tasks' => [['roleCode' => 'PURCHASE_DEPARTMENT']]],
        ]), $this->user(1));
    }

    /**
     * Выключить маршрут, назначенный дефолтом, нельзя: вид заявки остался бы без
     * маршрута, и подача перестала бы работать — без внятной причины для того,
     * кто нажал «выключить».
     */
    public function testDefaultTemplateCannotBeSwitchedOff(): void
    {
        $template = $this->defaultTemplate();

        $defaults = $this->createStub(PurchaseRouteDefaultRepository::class);
        $defaults->method('kindsDefaultingTo')->willReturn([PurchaseRequestKind::STANDARD]);

        $this->expectRouteError(SpaApiError::PURCHASE_ROUTE_IS_DEFAULT);
        $this->editorWith($this->createStub(PurchaseRouteTemplateRepository::class), $defaults)
            ->setActive($template, false, $this->user(1));
    }

    /** Дефолтом не назначить то, по чему нельзя подать заявку. */
    public function testInactiveTemplateCannotBecomeDefault(): void
    {
        $template = $this->defaultTemplate()->setActive(false);

        $this->expectRouteError(SpaApiError::PURCHASE_ROUTE_NOT_CONFIGURED);
        $this->editor($template)->setDefault(PurchaseRequestKind::STANDARD, $template, $this->user(1));
    }

    public function testTemplateCannotBecomeDefaultForForeignKind(): void
    {
        $template = $this->defaultTemplate()->setAllowedKinds([PurchaseRequestKind::FAST]);

        $this->expectRouteError(SpaApiError::PURCHASE_ROUTE_NOT_CONFIGURED);
        $this->editor($template)->setDefault(PurchaseRequestKind::STANDARD, $template, $this->user(1));
    }

    // Права на динамический этап

    /** На динамический этап идут только те, кому роль пула выдали в админке. */
    public function testOnlyPoolMembersCanBeAssignedToDynamicStage(): void
    {
        $template = $this->template('POOL', [
            $this->stage(PurchaseStagePurpose::TRIAGE, [$this->roleTask(PurchaseRoleCode::DIRECTOR)]),
            $this->stage(PurchaseStagePurpose::SIGN_OFF, [$this->dynamicTask(PurchaseRoleCode::PROFILE_DEPUTY)]),
        ]);

        $request = $this->request(PurchaseRequestKind::STANDARD, $this->user(1));
        $this->builder()->build($request, $template);

        $stage = $request->findStageByPosition(2);
        self::assertNotNull($stage);

        self::assertTrue(
            $this->accessOf(PurchaseRoleCode::PROFILE_DEPUTY)->canBeAssignedTo($stage, $this->user(2)),
        );
        self::assertFalse(
            $this->accessOf(PurchaseRoleCode::ACCOUNTING)->canBeAssignedTo($stage, $this->user(3)),
        );
    }

    // Обвязка

    private function builder(): ApprovalRouteBuilder
    {
        return new ApprovalRouteBuilder($this->em());
    }

    private function resolver(?PurchaseRouteTemplate $default): ApprovalRouteResolver
    {
        $defaults = $this->createStub(PurchaseRouteDefaultRepository::class);
        $defaults->method('findByKind')->willReturnCallback(
            static fn (PurchaseRequestKind $kind): ?PurchaseRouteDefault => $default === null
                ? null
                : (new PurchaseRouteDefault())->setKind($kind)->setTemplate($default),
        );

        return new ApprovalRouteResolver($this->createStub(PurchaseRouteTemplateRepository::class), $defaults);
    }

    private function editor(PurchaseRouteTemplate $template): ApprovalRouteEditor
    {
        $templates = $this->createStub(PurchaseRouteTemplateRepository::class);
        $templates->method('findByCode')->willReturn($template);

        return $this->editorWith($templates);
    }

    private function editorWith(
        PurchaseRouteTemplateRepository $templates,
        ?PurchaseRouteDefaultRepository $defaults = null,
    ): ApprovalRouteEditor {
        return new ApprovalRouteEditor(
            $this->em(),
            $templates,
            $defaults ?? $this->createStub(PurchaseRouteDefaultRepository::class),
        );
    }

    /**
     * Правка чистой заготовки присланным деревом этапов.
     *
     * @param list<array<string, mixed>> $stages
     * @throws PurchaseRouteException
     */
    private function editorUpdate(array $stages): void
    {
        $template = $this->template('DRAFT', []);
        $this->editor($template)->update($template, $this->payload($stages), $this->user(1));
    }

    /**
     * @param list<array<string, mixed>> $stages
     * @return array<string, mixed>
     */
    private function payload(array $stages): array
    {
        return [
            'name' => 'Тестовый маршрут',
            'allowedKinds' => [PurchaseRequestKind::STANDARD->value],
            'stages' => $stages,
            // Версия, которую админ видел в форме: без неё правку не принимают.
            'version' => 1,
        ];
    }

    /** @return array<string, mixed> */
    private function dynamicRow(): array
    {
        return [
            'assignmentType' => PurchaseTaskAssignment::DYNAMIC_USERS->value,
            'candidateRoleCode' => PurchaseRoleCode::PROFILE_DEPUTY->value,
        ];
    }

    /** Настоящее согласование на подменённой инфраструктуре: БД ему не нужна. */
    private function workflow(?PurchaseRouteTemplate $default): PurchaseApprovalWorkflow
    {
        $em = $this->em();

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static fn (object $message, array $stamps = []): Envelope => new Envelope($message),
        );

        $users = $this->createStub(UserRepository::class);
        $users->method('findByRoleName')->willReturn([]);

        $approverRoles = $this->createStub(PurchaseApproverRoleRepository::class);
        $approverRoles->method('findRoleCodesForUser')->willReturn([]);
        $approverRoles->method('findUsersByRoleCodes')->willReturn([]);

        $history = new PurchaseHistoryLogger($em);

        return new PurchaseApprovalWorkflow(
            $em,
            new PurchaseNotificationPublisher(
                new NotificationPublisher($bus),
                $users,
                new PurchaseRoster($approverRoles),
            ),
            $this->resolver($default),
            new ApprovalRouteBuilder($em),
            $history,
            new PurchaseRequestEditor($em, $history),
        );
    }

    /** Права на заявку от лица носителя этих ролей модуля. */
    private function accessOf(PurchaseRoleCode ...$codes): PurchaseAccess
    {
        $approverRoles = $this->createStub(PurchaseApproverRoleRepository::class);
        $approverRoles->method('findRoleCodesForUser')->willReturn(array_values($codes));

        return new PurchaseAccess(new PurchaseRoster($approverRoles));
    }

    private function em(): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);
        // Заглушка обязана выполнять тело транзакции: замена этапов идёт внутри
        // неё, и без этого тесты проверяли бы маршрут, которого не собирали.
        $em->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $work): mixed => $work($em),
        );

        return $em;
    }

    /** Маршрут, назначенный дефолтом обычным заявкам в большинстве сценариев. */
    private function defaultTemplate(): PurchaseRouteTemplate
    {
        return $this->template('STANDARD', [
            $this->stage(PurchaseStagePurpose::TRIAGE, [$this->roleTask(PurchaseRoleCode::DIRECTOR)]),
            $this->stage(PurchaseStagePurpose::SOURCING, [$this->roleTask(PurchaseRoleCode::PURCHASE_DEPARTMENT)]),
        ]);
    }

    /** @param list<PurchaseRouteTemplateStage> $stages */
    private function template(string $code, array $stages): PurchaseRouteTemplate
    {
        $template = (new PurchaseRouteTemplate())
            ->setCode($code)
            ->setName('Тестовый маршрут')
            ->setAllowedKinds([PurchaseRequestKind::STANDARD]);

        $position = 0;
        foreach ($stages as $stage) {
            $template->addStage($stage->setPosition(++$position));
        }

        return $template;
    }

    /** @param list<PurchaseRouteTemplateTask> $tasks */
    private function stage(PurchaseStagePurpose $purpose, array $tasks): PurchaseRouteTemplateStage
    {
        $stage = (new PurchaseRouteTemplateStage())
            ->setPurpose($purpose)
            ->setAllowsReject(!$purpose->isExecution());

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
            ->setRoleCode($code);
    }

    private function dynamicTask(PurchaseRoleCode $pool): PurchaseRouteTemplateTask
    {
        return (new PurchaseRouteTemplateTask())
            ->setAssignmentType(PurchaseTaskAssignment::DYNAMIC_USERS)
            ->setCandidateRoleCode($pool);
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
     * Форма снимка: позиция этапа и роли его задач.
     *
     * @return list<array{0: int, 1: list<string|null>}>
     */
    private function shape(PurchaseRequest $request): array
    {
        $shape = [];
        foreach ($request->getStages() as $stage) {
            $roles = [];
            foreach ($stage->getTasks() as $task) {
                $roles[] = $task->getRoleCode()?->value;
            }
            $shape[] = [$stage->getPosition(), $roles];
        }

        return $shape;
    }

    private function taskAt(PurchaseRequest $request, int $position): PurchaseApprovalTask
    {
        $stage = $request->findStageByPosition($position);
        self::assertNotNull($stage, sprintf('В маршруте нет этапа на позиции %d', $position));

        $task = $stage->getTasks()->first();
        self::assertInstanceOf(PurchaseApprovalTask::class, $task);

        return $task;
    }

    private function expectTransitionError(string $errorCode): void
    {
        $this->expectException(PurchaseTransitionException::class);
        $this->expectExceptionMessage($errorCode);
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
