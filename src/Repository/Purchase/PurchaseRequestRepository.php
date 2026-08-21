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
            ->distinct()
            ->addOrderBy('p.createdAt', 'ASC');

        $qb->andWhere($this->addressedExpr($roleCodes, 't', 'p'));
        if ($roleCodes !== []) {
            $qb->setParameter('roleCodes', $roleCodes, ArrayParameterType::STRING);
        }

        return $qb->getQuery()->getResult();
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

        $items = $qb
            ->addSelect("CASE WHEN pr.priority = 'URGENT' THEN 0 ELSE 1 END AS HIDDEN prioritySort")
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
                '(SELECT COALESCE(SUM(i.quantity * i.estimatedPrice), 0)
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
