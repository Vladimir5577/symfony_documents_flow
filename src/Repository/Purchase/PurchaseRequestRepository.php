<?php

namespace App\Repository\Purchase;

use App\Entity\Purchase\PurchaseApprovalStage;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseStageStatus;
use App\Enum\Purchase\PurchaseStagePurpose;
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
    /**
     * Сумма заявки в DQL — то же правило, что в PurchaseRequest::getTotalAmount():
     * снятые разбирающим позиции не считаются, количество — утверждённое, если оно
     * урезано. Одно выражение на список и на фильтр по сумме, чтобы реестр и
     * карточка не показывали разные суммы одной заявки (BE-20). Алиас позиции — «i».
     */
    private const EFFECTIVE_AMOUNT_DQL =
        'COALESCE(SUM(CASE WHEN i.excluded = false THEN COALESCE(i.approvedQuantity, i.quantity) ELSE 0 END * i.estimatedPrice), 0)';

    /** Очередь разбора отдаётся страницей: потолок общий с MAX_PAGE_SIZE контроллера. */
    public const TRIAGE_QUEUE_LIMIT = 100;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseRequest::class);
    }

    /**
     * Очередь разбора: заявки, где этап разбора ждёт решения этого человека.
     *
     * Гейта «ты директор» здесь нет — очередь и есть ответ на вопрос «что ждёт
     * меня»: пусто у того, к кому задачи разбора не адресованы.
     *
     * Прежде здесь стоял подзапрос «перед этим шагом не осталось незакрытых»:
     * шагов разбора в маршруте было два, и «шаг не решён» не значило «заявка
     * стоит на нём». Теперь разбор в маршруте один, а стоит ли на нём заявка,
     * говорит статус этапа.
     *
     * @param list<string> $roleCodes роли модуля, выданные пользователю
     * @return list<PurchaseRequest>
     */
    public function findTriageQueueFor(User $user, array $roleCodes): array
    {
        $qb = $this->createQueryBuilder('p')
            ->innerJoin('p.stages', 's')
            ->innerJoin('s.tasks', 't')
            ->andWhere('p.status = :onApproval')
            ->andWhere('s.purpose = :triage')
            ->andWhere('s.status = :active')
            ->andWhere('t.decision = :pending')
            ->setParameter('onApproval', PurchaseStatus::ON_APPROVAL)
            ->setParameter('triage', PurchaseStagePurpose::TRIAGE)
            ->setParameter('active', PurchaseStageStatus::ACTIVE)
            ->setParameter('pending', PurchaseTaskDecision::PENDING)
            ->setParameter('author', PurchaseTaskAssignment::AUTHOR)
            ->setParameter('user', $user)
            // Свою заявку разбирающий решать не может (PurchaseAccess::canDecide),
            // в очереди ей нечего делать — иначе модалка упиралась в неё первой.
            ->andWhere('p.createdBy <> :user')
            ->distinct()
            ->addOrderBy('p.createdAt', 'ASC');

        $qb->andWhere($this->addressedExpr($roleCodes, 't', 'p'));
        if ($roleCodes !== []) {
            $qb->setParameter('roleCodes', $roleCodes, ArrayParameterType::STRING);
        }

        // BE-25: очередь без потолка после отпуска директора выливалась в сотни
        // карточек с ленивыми коллекциями на каждую. Страница + прогрев коллекций
        // тем же приёмом, что в findFiltered (warmUpStages).
        $items = $qb->setMaxResults(self::TRIAGE_QUEUE_LIMIT)->getQuery()->getResult();
        $this->warmUpStages($items);
        $this->warmUpTriageCard($items);

        return $items;
    }

    /**
     * Сколько всего заявок ждёт разбора этого человека — для `total` в ответе,
     * когда очередь длиннее страницы.
     *
     * @param list<string> $roleCodes
     */
    public function countTriageQueueFor(User $user, array $roleCodes): int
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(DISTINCT p.id)')
            ->innerJoin('p.stages', 's')
            ->innerJoin('s.tasks', 't')
            ->andWhere('p.status = :onApproval')
            ->andWhere('s.purpose = :triage')
            ->andWhere('s.status = :active')
            ->andWhere('t.decision = :pending')
            ->setParameter('onApproval', PurchaseStatus::ON_APPROVAL)
            ->setParameter('triage', PurchaseStagePurpose::TRIAGE)
            ->setParameter('active', PurchaseStageStatus::ACTIVE)
            ->setParameter('pending', PurchaseTaskDecision::PENDING)
            ->setParameter('author', PurchaseTaskAssignment::AUTHOR)
            ->setParameter('user', $user)
            ->andWhere('p.createdBy <> :user');

        $qb->andWhere($this->addressedExpr($roleCodes, 't', 'p'));
        if ($roleCodes !== []) {
            $qb->setParameter('roleCodes', $roleCodes, ArrayParameterType::STRING);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Прогрев коллекций карточки для очереди разбора: модалка читает позиции и
     * организацию; без прогрева presentDetail ходил в БД за каждой заявкой.
     *
     * @param list<PurchaseRequest> $items
     */
    private function warmUpTriageCard(array $items): void
    {
        if ($items === []) {
            return;
        }

        $ids = array_map(static fn (PurchaseRequest $request): int => (int) $request->getId(), $items);

        $this->createQueryBuilder('wpr')
            ->leftJoin('wpr.items', 'wit')->addSelect('wit')
            ->leftJoin('wit.categoryItem', 'wci')->addSelect('wci')
            ->leftJoin('wpr.organization', 'wor')->addSelect('wor')
            ->leftJoin('wpr.createdBy', 'wcb')->addSelect('wcb')
            ->andWhere('wpr.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $this->createQueryBuilder('wpr')
            ->leftJoin('wpr.files', 'wfi')->addSelect('wfi')
            ->leftJoin('wpr.comments', 'wco')->addSelect('wco')
            ->andWhere('wpr.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /**
     * Список с фильтрами и пагинацией. Срочные — сверху, затем новые.
     *
     * @param int|null                  $createdById       только заявки этого автора (null = без ограничения)
     * @param list<PurchaseStatus>|null $statuses          ограничение по статусам (null = все)
     * @param int|null                  $approverUserId    только заявки, где пользователь есть в маршруте
     * @param list<string>              $approverRoleCodes его роли модуля — для ролевых задач маршрута
     * @param float|null                $minAmount         скрыть заявки дешевле порога (сумма считается из позиций)
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
    ): array {
        $qb = $this->createFilteredQueryBuilder($createdById, $statuses, $search, $minAmount);

        // «Я согласант» — заявки, где человек есть в маршруте: лично, через роль
        // или как автор задачи, адресованной заявителю.
        if ($approverUserId !== null) {
            $qb->join(PurchaseApprovalStage::class, 'fs', 'WITH', 'fs.purchaseRequest = pr')
                ->join('fs.tasks', 'ft')
                ->setParameter('user', $approverUserId)
                ->setParameter('author', PurchaseTaskAssignment::AUTHOR)
                ->andWhere($this->addressedExpr($approverRoleCodes, 'ft', 'pr'))
                ->distinct();

            if ($approverRoleCodes !== []) {
                $qb->setParameter('roleCodes', $approverRoleCodes, ArrayParameterType::STRING);
            }
        }

        $total = (int) (clone $qb)
            ->select('COUNT(pr.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // Связи «к одному» подтягиваем сразу. Без этого презентер на каждой
        // строке лениво дёргал организацию (плюс обход её родителей для
        // полного пути), категорию, автора, исполнителя и должности обоих —
        // на странице в сто записей это сотни отдельных запросов.
        //
        // Коллекцию позиций тут фетчить нельзя: соединение «к многим» ломает
        // setMaxResults, страница поехала бы. Количество позиций и сумму
        // считает отдельный агрегирующий запрос — sumAndCountItemsByRequestIds.
        $items = $qb
            ->addSelect("CASE WHEN pr.priority = 'URGENT' THEN 0 ELSE 1 END AS HIDDEN prioritySort")
            ->leftJoin('pr.organization', 'org')->addSelect('org')
            ->leftJoin('pr.category', 'cat')->addSelect('cat')
            ->leftJoin('pr.createdBy', 'author')->addSelect('author')
            ->leftJoin('author.worker', 'authorWorker')->addSelect('authorWorker')
            ->leftJoin('pr.executor', 'executor')->addSelect('executor')
            ->leftJoin('executor.worker', 'executorWorker')->addSelect('executorWorker')
            ->orderBy('prioritySort', 'ASC')
            ->addOrderBy('pr.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        $this->warmUpStages($items);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Количество позиций и их сумма по списку заявок — одним запросом.
     *
     * Нужно, чтобы список не загружал коллекцию позиций у каждой заявки ради
     * getTotalAmount() и count(): именно это давало основной вес страницы.
     *
     * @param list<int> $requestIds
     *
     * @return array<int, array{count: int, total: float}> ключ — id заявки
     */
    public function sumAndCountItemsByRequestIds(array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }

        // COUNT намеренно по всем позициям: карточка (presentDetail) тоже считает
        // getItems()->count() со снятыми. Меняется только сумма.
        $rows = $this->getEntityManager()->createQuery(
            'SELECT IDENTITY(i.purchaseRequest) AS requestId,
                    COUNT(i.id) AS itemsCount,
                    ' . self::EFFECTIVE_AMOUNT_DQL . ' AS totalAmount
               FROM App\Entity\Purchase\PurchaseRequestItem i
              WHERE i.purchaseRequest IN (:ids)
              GROUP BY i.purchaseRequest'
        )
            ->setParameter('ids', $requestIds)
            ->getArrayResult();

        $aggregates = [];
        foreach ($rows as $row) {
            $aggregates[(int) $row['requestId']] = [
                'count' => (int) $row['itemsCount'],
                'total' => round((float) $row['totalAmount'], 2),
            ];
        }

        return $aggregates;
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

        $this->createQueryBuilder('wpr')
            ->leftJoin('wpr.stages', 'wst')->addSelect('wst')
            ->leftJoin('wst.tasks', 'wta')->addSelect('wta')
            ->leftJoin('wta.assigneeUser', 'wau')->addSelect('wau')
            ->leftJoin('wta.decidedBy', 'wdb')->addSelect('wdb')
            ->andWhere('wpr.id IN (:ids)')
            ->setParameter('ids', array_map(
                static fn (PurchaseRequest $request): int => (int) $request->getId(),
                $items,
            ))
            ->getQuery()
            ->getResult();
    }

    /**
     * Количество заявок по каждому статусу (для счётчиков-бейджей).
     *
     * @param int|null $createdById
     * @return array<string, int> [status value => count]
     */
    public function countByStatuses(?int $createdById): array
    {
        $qb = $this->createFilteredQueryBuilder($createdById, null, null)
            ->select('pr.status AS status, COUNT(pr.id) AS cnt')
            ->groupBy('pr.status');

        $counts = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $status = $row['status'] instanceof PurchaseStatus ? $row['status']->value : (string) $row['status'];
            $counts[$status] = (int) $row['cnt'];
        }

        return $counts;
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
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('pr');

        if ($minAmount !== null) {
            // Сумма не хранится в колонке (считается из позиций) — фильтруем скалярным подзапросом,
            // чтобы клон под COUNT(pr.id) работал без группировок.
            $qb->andWhere(
                '(SELECT ' . self::EFFECTIVE_AMOUNT_DQL . '
                  FROM App\Entity\Purchase\PurchaseRequestItem i
                  WHERE i.purchaseRequest = pr) >= :minAmount'
            )->setParameter('minAmount', $minAmount);
        }

        if ($createdById !== null) {
            $qb->andWhere('pr.createdBy = :createdById')
                ->setParameter('createdById', $createdById);
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
            $qb->setParameter('search', '%' . mb_strtolower($search) . '%');
        }

        return $qb;
    }
}
