<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseApprovalStep;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRouteTemplateStep;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseApproverKind;
use App\Enum\Purchase\PurchaseRequestKind;
use App\Enum\Purchase\PurchaseStepPurpose;
use App\Repository\Purchase\PurchaseRouteTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Маршрут согласования заявки: собирается из заготовки вида заявки
 * (PurchaseRouteTemplate) и замораживается в шаги заявки при подаче.
 *
 * Кнопка создания по-прежнему решает, какой это маршрут — быстрый или обычный.
 * Из чего он состоит, решает заготовка в админке, а правка заготовки касается
 * только новых подач: у заявки в пути шаги свои.
 *
 * Другого источника маршрута нет. Умолчания в коде здесь не держим намеренно:
 * копия регламента, которую месяцами не синхронизируют с админкой, — это второй
 * ответ на вопрос «как согласуют закупки», и однажды кто-то получит по ней
 * маршрут, по которому давно не работают. Поэтому ненастроенный маршрут — это
 * не «возьмём типовой», а отказ подать заявку.
 *
 * Замы появляются не при подаче, а в момент решения директора — до него
 * неизвестно, кто они и есть ли вообще. Поэтому слот замов в шаг не
 * превращается: он резервирует позицию на заявке, и вставка в середину не
 * двигает соседей.
 */
final class ApprovalRouteBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PurchaseRouteTemplateRepository $templates,
    ) {
    }

    /**
     * Построить маршрут заявки. Существующие шаги сносятся целиком: повторная
     * подача после доработки начинает согласование с нуля — и по нынешней
     * заготовке, а не по той, что действовала в первый раз.
     * Flush — на вызывающей стороне.
     *
     * @throws PurchaseTransitionException маршрут этого вида не настроен
     */
    public function build(PurchaseRequest $request): void
    {
        // Проверяем до сноса старых шагов: у заявки, которую вернули на
        // доработку, согласование уже шло, и отказ в подаче не должен стирать
        // след того, что было.
        $specs = $this->stepsFor($request->getCreatedAs());
        if ($specs === []) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_ROUTE_NOT_CONFIGURED);
        }

        foreach ($request->getSteps()->toArray() as $step) {
            $request->removeStep($step);
            $this->em->remove($step);
        }

        $request->setApproversPosition(null);

        foreach ($specs as $spec) {
            if ($spec->isApproversSlot()) {
                $request->setApproversPosition($request->getApproversPosition() ?? $spec->getPosition());

                continue;
            }

            // Ролевой шаг без роли закрыть некому — заявка встала бы насмерть.
            // Такое возможно только если роль убрали из PurchaseRoleCode уже
            // после сохранения заготовки: тогда шаг просто выпадает из маршрута.
            $role = $spec->getRoleCode();
            if ($role === null) {
                continue;
            }

            $step = (new PurchaseApprovalStep())
                ->setPosition($spec->getPosition())
                ->setApproverKind(PurchaseApproverKind::ROLE)
                ->setRoleCode($role)
                ->setTitle($spec->resolveTitle())
                ->setPurpose($spec->getPurpose())
                ->setRequiresFileType($spec->getRequiresFileType());

            $request->addStep($step);
            $this->em->persist($step);
        }
    }

    /**
     * Повесить на заявку профильных замов, отмеченных директором.
     *
     * Встают они на зарезервированную позицию — ту, что заморозилась при подаче.
     * Слота в маршруте нет — назначать некуда, и вызывающий обязан это проверить
     * заранее (PurchaseRequestService::directorSend).
     *
     * Автор в список не попадает: сам себе согласантом человек не бывает, а
     * заявка на нём бы и застряла. Flush — на вызывающей стороне.
     *
     * @param list<User> $users
     * @return list<User> кого реально повесили — им уходит уведомление о назначении
     */
    public function addApprovers(PurchaseRequest $request, array $users): array
    {
        $position = $request->getApproversPosition();
        if ($position === null) {
            return [];
        }

        $authorId = $request->getCreatedBy()?->getId();
        $assigned = [];
        $seen = [];

        foreach ($users as $user) {
            $userId = $user->getId();
            if ($userId === null || $userId === $authorId || isset($seen[$userId])) {
                continue;
            }
            $seen[$userId] = true;

            $step = (new PurchaseApprovalStep())
                ->setPosition($position)
                ->setApproverKind(PurchaseApproverKind::USER)
                ->setApproverUser($user)
                ->setPurpose(PurchaseStepPurpose::SIGN_OFF);

            $request->addStep($step);
            $this->em->persist($step);
            $assigned[] = $user;
        }

        return $assigned;
    }

    /**
     * Превью маршрута для формы создания: что получится при выбранной кнопке.
     * Считает бэк, чтобы фронт не дублировал правила. Параллельные шаги
     * склеиваются в одну строку — «Бухгалтерия и Юристы».
     *
     * @return list<array{position: int, title: string}>
     */
    public function preview(PurchaseRequestKind $kind): array
    {
        $rows = [];

        foreach ($this->stepsFor($kind) as $spec) {
            $title = $spec->isApproversSlot()
                ? $spec->resolveTitle() . ' (назначает директор)'
                : $spec->resolveTitle();

            $position = $spec->getPosition();
            $rows[$position] = isset($rows[$position]) ? $rows[$position] . ' и ' . $title : $title;
        }

        ksort($rows);

        $preview = [];
        foreach ($rows as $position => $title) {
            $preview[] = ['position' => $position, 'title' => $title];
        }

        return $preview;
    }

    /**
     * Шаги настроенной заготовки по порядку; пусто — маршрут не настроен.
     *
     * @return list<PurchaseRouteTemplateStep>
     */
    private function stepsFor(PurchaseRequestKind $kind): array
    {
        $steps = array_values($this->templates->findByKind($kind)?->getSteps()->toArray() ?? []);

        usort(
            $steps,
            static fn (PurchaseRouteTemplateStep $a, PurchaseRouteTemplateStep $b): int
                => $a->getPosition() <=> $b->getPosition(),
        );

        return $steps;
    }
}
