<?php

namespace App\Repository\Purchase;

use App\Entity\Purchase\PurchaseApprovalStep;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseStatus;
use App\Enum\Purchase\PurchaseStepDecision;
use App\Enum\Purchase\PurchaseStepPurpose;
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
     * Очередь разбора: заявки, где шаг разбора ждёт решения этого человека.
     *
     * Гейта «ты директор» здесь больше нет — очередь и есть ответ на вопрос
     * «что ждёт меня»: пусто у того, к кому шаги разбора не адресованы.
     *
     * Шагов разбора в маршруте два — первичное решение и итоговое, — поэтому
     * «шаг не решён» ещё не значит «заявка стоит на нём»: сразу после подачи не
     * решены оба. Отсюда подзапрос: перед этим шагом не должно остаться
     * незакрытых, то есть указатель стоит именно на нём.
     *
     * @param list<string> $roleCodes роли модуля, выданные пользователю
     * @return list<PurchaseRequest>
     */
    public function findTriageQueueFor(User $user, array $roleCodes): array
    {
        $qb = $this->createQueryBuilder('p')
            ->innerJoin('p.steps', 's')
            ->andWhere('p.status = :onApproval')
            ->andWhere('s.purpose = :triage')
            ->andWhere('s.decision = :pending')
            ->andWhere(
                'NOT EXISTS (
                    SELECT 1 FROM ' . PurchaseApprovalStep::class . ' earlier
                    WHERE earlier.purchaseRequest = p
                      AND earlier.decision = :pending
                      AND earlier.position < s.position
                )'
            )
            ->setParameter('onApproval', PurchaseStatus::ON_APPROVAL)
            ->setParameter('triage', PurchaseStepPurpose::TRIAGE)
            ->setParameter('pending', PurchaseStepDecision::PENDING)
            ->setParameter('user', $user)
            ->distinct()
            ->addOrderBy('p.createdAt', 'ASC');

        if ($roleCodes === []) {
            $qb->andWhere('s.approverUser = :user');
        } else {
            $qb->andWhere('s.approverUser = :user OR s.roleCode IN (:roleCodes)')
                ->setParameter('roleCodes', $roleCodes, ArrayParameterType::STRING);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Список с фильтрами и пагинацией. Срочные — сверху, затем новые.
     *
     * @param int|null                  $createdById    только заявки этого автора (null = без ограничения)
     * @param list<PurchaseStatus>|null $statuses        ограничение по статусам (null = все)
     * @param int|null                  $approverUserId    только заявки, где пользователь есть в маршруте
     * @param list<string>              $approverRoleCodes его роли модуля — для ролевых шагов маршрута
     * @param float|null                $minAmount       скрыть заявки дешевле порога (сумма считается из позиций)
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

        // «Я согласант» — заявки, где человек есть в маршруте: лично или через роль.
        if ($approverUserId !== null) {
            $qb->join(PurchaseApprovalStep::class, 'st', 'WITH', 'st.purchaseRequest = pr')
                ->setParameter('approverUserId', $approverUserId)
                ->distinct();

            if ($approverRoleCodes === []) {
                $qb->andWhere('st.approverUser = :approverUserId');
            } else {
                $qb->andWhere('st.approverUser = :approverUserId OR st.roleCode IN (:approverRoleCodes)')
                    ->setParameter('approverRoleCodes', $approverRoleCodes, ArrayParameterType::STRING);
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

        $this->warmUpSteps($items);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Догрузить шаги маршрута для страницы списка одним запросом.
     *
     * Презентеру списка шаги нужны у каждой строки: «у кого сейчас заявка» и
     * «моя подпись, которую ещё можно снять». Без этого Doctrine поднимает
     * коллекцию лениво на каждую строку — двадцать заявок, двадцать запросов.
     *
     * Fetch-join прямо в основной запрос делать нельзя: коллекция размножает
     * строки, и setMaxResults начинает резать не заявки, а их шаги. Поэтому
     * вторым запросом по id уже отобранной страницы — он подтягивает те же
     * объекты из identity map и заодно инициализирует их коллекции.
     *
     * @param list<PurchaseRequest> $items
     */
    private function warmUpSteps(array $items): void
    {
        if ($items === []) {
            return;
        }

        $this->createQueryBuilder('wpr')
            ->leftJoin('wpr.steps', 'wst')->addSelect('wst')
            ->leftJoin('wst.approverUser', 'wau')->addSelect('wau')
            ->leftJoin('wst.decidedBy', 'wdb')->addSelect('wdb')
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
