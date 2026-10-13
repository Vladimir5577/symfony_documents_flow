<?php

namespace App\Repository\Purchase;

use App\Entity\Organization\AbstractOrganization;
use App\Entity\Purchase\PurchaseApprovalTask;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRouteTemplate;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseProgressStatus;
use App\Enum\Purchase\PurchaseRoleCode;
use App\Enum\Purchase\PurchaseStagePurpose;
use App\Enum\Purchase\PurchaseStageStatus;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\Purchase\PurchaseTaskAssignment;
use App\Enum\Purchase\PurchaseTaskDecision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseRequest>
 */
class PurchaseRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseRequest::class);
    }

    /**
     * Заявки, которые ссылаются на заготовку: назначенный маршрут или уже собранный снимок.
     *
     * @return list<PurchaseRequest>
     */
    public function findUsingTemplate(PurchaseRouteTemplate $template): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.files', 'f')->addSelect('f')
            ->andWhere('p.routeTemplate = :template OR p.appliedRouteTemplate = :template')
            ->setParameter('template', $template)
            ->getQuery()
            ->getResult();
    }

    /**
     * Inbox постранично: старые заявки первыми, маршрут прогрет для строки списка.
     *
     * Карточки целиком здесь не собираем: у админа в очереди десятки тысяч
     * заявок, и полный ответ не помещается ни во время, ни в память запроса.
     * Карточку текущей заявки модалка берёт отдельно по GET /{id}.
     *
     * @param list<string> $roleCodes роли модуля, выданные пользователю
     * @return array{items: list<PurchaseRequest>, total: int}
     */
    public function findDecisionRequiredPage(?User $forUser, array $roleCodes, ?string $lane, int $page, int $pageSize): array
    {
        $qb = $this->decisionRequiredQuery($forUser, $roleCodes, $lane);

        $total = (int) (clone $qb)
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // id вторым ключом: у заявок бывает одинаковое время создания, и без
        // него соседние страницы повторяют или теряют строки.
        $items = $qb
            ->orderBy('p.createdAt', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        $this->warmUpStages($items);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Размер очереди для бейджа — без загрузки заявок.
     *
     * @param list<string> $roleCodes
     */
    public function countDecisionRequired(?User $forUser, array $roleCodes, ?string $lane = null): int
    {
        return (int) $this->decisionRequiredQuery($forUser, $roleCodes, $lane)
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Заявки, где сейчас чья-то очередь — любой этап, не только разбор.
     *
     * Зеркало PurchaseAccess::findMyActiveTask: заявка в маршруте, этап ACTIVE,
     * на нём нерешённая задача, адресованная лично, ролью или как автору.
     * Гейта по роли модуля нет — пусто, если решать нечего. $forUser = null —
     * без фильтра «адресована мне»: так видит очередь ROLE_ADMIN.
     *
     * $lane делит очередь директора: 'pay' — его подпись прямо перед оплатой,
     * 'decision' — всё остальное, null — вся очередь. Правило то же, что у
     * фронта в isDirectorPayHandoff.
     *
     * Отбор — подзапросом по id, а не DISTINCT по строкам заявки: так
     * сортировка и LIMIT идут по самой заявке, а этапы и задачи строк не множат.
     *
     * @param list<string> $roleCodes
     */
    private function decisionRequiredQuery(?User $forUser, array $roleCodes, ?string $lane): QueryBuilder
    {
        $queued = $this->getEntityManager()->createQueryBuilder()
            ->select('dp.id')
            ->from(PurchaseRequest::class, 'dp')
            ->innerJoin('dp.stages', 'ds')
            ->innerJoin('ds.tasks', 'dt')
            ->andWhere('dp.status IN (:inRoute)')
            ->andWhere('ds.status = :active')
            ->andWhere('dt.decision = :pending');

        $qb = $this->createQueryBuilder('p')
            ->setParameter('inRoute', [
                PurchaseStatus::ON_APPROVAL,
                PurchaseStatus::APPROVED,
                PurchaseStatus::INVOICE_PAID,
                PurchaseStatus::DELIVERED,
            ])
            ->setParameter('active', PurchaseStageStatus::ACTIVE)
            ->setParameter('pending', PurchaseTaskDecision::PENDING);

        if ($forUser !== null) {
            $queued->andWhere($this->addressedExpr($roleCodes, 'dt', 'dp'));
            $qb->setParameter('author', PurchaseTaskAssignment::AUTHOR)
                ->setParameter('user', $forUser);
            if ($roleCodes !== []) {
                $qb->setParameter('roleCodes', $roleCodes, ArrayParameterType::STRING);
            }
        }

        if ($lane === 'pay' || $lane === 'decision') {
            // roleCode IS NOT NULL — иначе у личной задачи условие станет NULL,
            // и NOT(...) выкинет её из «решения» вместо того, чтобы оставить.
            $payHandoff = 'ds.purpose = :laneSignOff
                AND dt.roleCode IS NOT NULL AND dt.roleCode = :laneDirector
                AND EXISTS (
                    SELECT 1 FROM App\Entity\Purchase\PurchaseApprovalStage laneNext
                    WHERE laneNext.purchaseRequest = dp
                      AND laneNext.purpose = :lanePayment
                      AND laneNext.position = (
                          SELECT MIN(laneMin.position) FROM App\Entity\Purchase\PurchaseApprovalStage laneMin
                          WHERE laneMin.purchaseRequest = dp
                            AND laneMin.position > ds.position
                            AND laneMin.status <> :laneSkipped
                      )
                )';
            $queued->andWhere($lane === 'pay' ? '(' . $payHandoff . ')' : 'NOT (' . $payHandoff . ')');
            $qb->setParameter('laneSignOff', PurchaseStagePurpose::SIGN_OFF)
                ->setParameter('laneDirector', PurchaseRoleCode::DIRECTOR)
                ->setParameter('lanePayment', PurchaseStagePurpose::PAYMENT)
                ->setParameter('laneSkipped', PurchaseStageStatus::SKIPPED);
        }

        return $qb->andWhere($qb->expr()->in('p.id', $queued->getDQL()));
    }

    /**
     * Список с фильтрами и пагинацией. Срочные — сверху, затем новые.
     *
     * @param int|null                  $createdById       только заявки этого автора (null = без ограничения)
     * @param list<PurchaseStatus>|null $statuses          ограничение по статусам (null = все)
     * @param int|null                  $approverUserId    только заявки, где пользователь есть в маршруте
     * @param list<string>              $approverRoleCodes его роли модуля — для ролевых задач маршрута
     * @param float|null                $minAmount         скрыть заявки дешевле порога (по колонке total_amount_kopecks)
     * @param bool                      $overdue           только заявки с открытым просроченным этапом
     * @return array{items: list<PurchaseRequest>, total: int}
     */
    public function findByFilters(
        ?int $createdById,
        ?array $statuses,
        ?string $search,
        int $page,
        int $pageSize,
        ?int $approverUserId = null,
        ?float $minAmount = null,
        array $approverRoleCodes = [],
        bool $overdue = false,
        ?string $progress = null,
    ): array {
        $ownerAndApprover = $createdById !== null && $approverUserId !== null;
        $qb = $this->createFilteredQueryBuilder(
            $ownerAndApprover ? null : $createdById,
            $statuses,
            $search,
            $minAmount,
            $overdue,
            $progress,
        );

        // «Я согласант» — заявки, где человек есть в маршруте: лично, через роль
        // или как автор задачи, адресованной заявителю. Вместе с createdBy —
        // свои ИЛИ назначенные: иначе зам видит заявку только в inbox.
        //
        // Через pr.id IN (подзапрос по задачам), а не JOIN этапов и задач:
        // JOIN размножал строки, и COUNT(DISTINCT) на каждой странице перебирал
        // все задачи модуля (замер 13.10 на 100k заявок, SQL на запрос списка:
        // юрист 391 → 276 мс, сотрудник без ролей 672 → 58 мс).
        if ($approverUserId !== null) {
            $participant = [$this->requestIdsOfTasks('pt1', 'pt1.assigneeUser = :user')];
            $qb->setParameter('user', $approverUserId);

            if ($approverRoleCodes !== []) {
                $participant[] = $this->requestIdsOfTasks('pt2', 'pt2.roleCode IN (:roleCodes)');
                $qb->setParameter('roleCodes', $approverRoleCodes, ArrayParameterType::STRING);
            }

            if ($ownerAndApprover) {
                // Задача «автору» адресована создателю — его заявки и так в списке.
                $participant[] = 'pr.createdBy = :createdById';
                $qb->setParameter('createdById', $createdById);
            } else {
                // Подзапрос сразу по своим заявкам: задач «автору» по всему
                // модулю десятки тысяч, и хеш по ним строился на каждый запрос.
                $participant[] = $this->requestIdsOfTasks(
                    'pt3',
                    'pt3.assignmentType = :author AND pt3r.createdBy = :user',
                    'JOIN pt3s.purchaseRequest pt3r',
                );
                $qb->setParameter('author', PurchaseTaskAssignment::AUTHOR);
            }

            $qb->andWhere('(' . implode(' OR ', $participant) . ')');
        }

        $total = (int) (clone $qb)
            ->select('COUNT(pr.id)')
            ->getQuery()
            ->getSingleScalarResult();

        if ($total > 0) {
            $this->warmUpOrganizations();
        }

        // Связи «к одному» строк не размножают — их можно брать прямо в запрос
        // страницы. Без этого автор, исполнитель, их Worker и категория
        // поднимались отдельным SQL на каждую строку.
        $items = $qb
            ->leftJoin('pr.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('cb.worker', 'cbw')->addSelect('cbw')
            ->leftJoin('pr.executor', 'ex')->addSelect('ex')
            ->leftJoin('ex.worker', 'exw')->addSelect('exw')
            ->leftJoin('pr.category', 'cat')->addSelect('cat')
            // id — второй ключ: заявки одной секунды иначе меняются местами
            // между запросами страниц, и строка попадает на две страницы.
            ->orderBy('pr.createdAt', 'DESC')
            ->addOrderBy('pr.id', 'DESC')
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        $this->warmUpStages($items);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * DQL-условие «заявка есть среди заявок задач, отобранных $condition».
     * Этап задачи — псевдоним {$alias}s.
     */
    private function requestIdsOfTasks(string $alias, string $condition, string $join = ''): string
    {
        return sprintf(
            'pr.id IN (SELECT IDENTITY(%1$ss.purchaseRequest) FROM %2$s %1$s JOIN %1$s.stage %1$ss %3$s WHERE %4$s)',
            $alias,
            PurchaseApprovalTask::class,
            $join,
            $condition,
        );
    }

    /**
     * Задача адресована пользователю: лично, через роль модуля или как автору.
     *
     * @param list<string> $roleCodes
     */
    private function addressedExpr(array $roleCodes, string $task, string $request): string
    {
        $personal = sprintf(
            '%s.assigneeUser = :user OR (%s.assignmentType = :author AND %s.createdBy = :user)',
            $task,
            $task,
            $request,
        );

        return $roleCodes === []
            ? '(' . $personal . ')'
            : sprintf('(%s OR %s.roleCode IN (:roleCodes))', $personal, $task);
    }

    /**
     * Догрузить маршрут для страницы списка одним запросом.
     *
     * Презентеру списка этапы нужны у каждой строки: «у кого сейчас заявка» и
     * «моя подпись, которую ещё можно снять». Без этого Doctrine поднимает
     * коллекцию лениво на каждую строку — двадцать заявок, двадцать запросов.
     *
     * Fetch-join прямо в основной запрос делать нельзя: коллекция размножает
     * строки, и setMaxResults начинает резать не заявки, а их этапы. Поэтому
     * вторым запросом по id уже отобранной страницы — он подтягивает те же
     * объекты из identity map и заодно инициализирует их коллекции.
     *
     * @param list<PurchaseRequest> $items
     */
    private function warmUpStages(array $items): void
    {
        if ($items === []) {
            return;
        }

        $ids = array_map(
            static fn (PurchaseRequest $request): int => (int) $request->getId(),
            $items,
        );

        // Worker у User — обратная сторона OneToOne: Doctrine поднимает его
        // отдельным SQL на каждого пользователя, если не взять в тот же запрос.
        $this->createQueryBuilder('wpr')
            ->leftJoin('wpr.stages', 'wst')->addSelect('wst')
            ->leftJoin('wst.tasks', 'wta')->addSelect('wta')
            ->leftJoin('wta.assigneeUser', 'wau')->addSelect('wau')
            ->leftJoin('wau.worker', 'wauw')->addSelect('wauw')
            ->leftJoin('wta.decidedBy', 'wdb')->addSelect('wdb')
            ->leftJoin('wdb.worker', 'wdbw')->addSelect('wdbw')
            ->andWhere('wpr.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        // Позиции — отдельным запросом: в одном с этапами и задачами коллекции
        // перемножились бы. Нужны строке ради суммы и числа позиций.
        $this->createQueryBuilder('wpi')
            ->leftJoin('wpi.items', 'wit')->addSelect('wit')
            ->andWhere('wpi.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /**
     * Загрузить справочник организаций одним запросом.
     *
     * Организация — корень иерархии с наследованием (Organization/Filial/
     * Department), и ссылку на неё Doctrine не может отдать ленивой: грузит
     * сразу отдельным SQL — у заявки, у каждого пользователя строки и у каждого
     * родителя в пути. Уже загруженные объекты Doctrine берёт из памяти, поэтому
     * весь справочник (это сотни строк, не тысячи) дешевле, чем 17–38 запросов
     * на страницу. Два родителя — в том же запросе, иначе родитель, ещё не
     * попавший в память, снова поднимается отдельно.
     */
    private function warmUpOrganizations(): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->select('wo', 'wop', 'wopp')
            ->from(AbstractOrganization::class, 'wo')
            ->leftJoin('wo.parent', 'wop')
            ->leftJoin('wop.parent', 'wopp')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param int|null                  $createdById
     * @param list<PurchaseStatus>|null $statuses
     */
    private function createFilteredQueryBuilder(
        ?int $createdById,
        ?array $statuses,
        ?string $search,
        ?float $minAmount = null,
        bool $overdue = false,
        ?string $progress = null,
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('pr');

        if ($minAmount !== null) {
            // Колонка — та же сумма, что в строке (PurchaseRequest::getTotalAmountKopecks).
            // Сумма в целых копейках, поэтому порог округляем вверх; round() срезает
            // хвост float (0.07 * 100 = 7.000000000000001), иначе ceil дал бы 8.
            // Порог больше int (min_amount=1e20) при (int) стал бы отрицательным и пустил бы всё.
            $minKopecks = ceil(round($minAmount * 100, 4));
            $qb->andWhere('pr.totalAmountKopecks >= :minKopecks')
                ->setParameter('minKopecks', $minKopecks >= PHP_INT_MAX ? PHP_INT_MAX : (int) $minKopecks);
        }

        if ($createdById !== null) {
            $qb->andWhere('pr.createdBy = :createdById')
                ->setParameter('createdById', $createdById);
        }

        // Возвращённая и отменённая заявки хранят активный этап и прежний
        // progressStatus, но никого не ждут: в «Просроченные», «Новые» и
        // «В процессе» они не попадают.
        if ($overdue || $progress === 'new' || $progress === 'progress') {
            $qb->andWhere('pr.status IN (:inRouteStatuses)')
                ->setParameter('inRouteStatuses', PurchaseStatus::getInRoute());
        }

        if ($overdue) {
            $qb->andWhere('EXISTS (
                SELECT 1 FROM App\Entity\Purchase\PurchaseApprovalStage overdueStage
                WHERE overdueStage.purchaseRequest = pr
                  AND overdueStage.status = :overdueStatus
                  AND overdueStage.dueAt IS NOT NULL
                  AND overdueStage.dueAt < :overdueNow
            )')
                ->setParameter('overdueStatus', PurchaseStageStatus::ACTIVE)
                ->setParameter('overdueNow', new \DateTimeImmutable());
        }

        if ($progress === 'new' || $progress === 'progress' || $progress === 'done') {
            $qb->andWhere('pr.progressStatus = :progressStatus')
                ->setParameter('progressStatus', PurchaseProgressStatus::from($progress));
        }

        if ($statuses !== null) {
            $qb->andWhere('pr.status IN (:statuses)')
                ->setParameter('statuses', $statuses);
        }

        if ($search !== null && $search !== '') {
            if (ctype_digit($search)) {
                $qb->andWhere('pr.id = :searchId OR LOWER(pr.title) LIKE :search')
                    ->setParameter('searchId', (int) $search);
            } else {
                $qb->andWhere('LOWER(pr.title) LIKE :search');
            }
            // % и _ в запросе — обычные символы, не шаблон LIKE. Обратный слеш —
            // экранирующий символ LIKE в PostgreSQL по умолчанию.
            // Индекс по LOWER(title) — триграммный GIN (миграция Version20261013180000).
            $qb->setParameter('search', '%' . addcslashes(mb_strtolower($search), '\\%_') . '%');
        }

        return $qb;
    }
}
