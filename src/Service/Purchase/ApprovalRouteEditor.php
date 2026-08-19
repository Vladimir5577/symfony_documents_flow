<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseRouteTemplate;
use App\Entity\Purchase\PurchaseRouteTemplateStep;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseApproverKind;
use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseRequestKind;
use App\Enum\Purchase\PurchaseRoleCode;
use App\Enum\Purchase\PurchaseStepPurpose;
use App\Repository\Purchase\PurchaseRouteTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Правка заготовки маршрута из админки.
 *
 * Заготовка приходит целиком и заменяется целиком: частичных правок шагов нет,
 * потому что порядок и параллельность — свойства всего списка, а не отдельной
 * строки, и «подвинь один шаг» неизбежно разъезжается с тем, что видит админ.
 *
 * Здесь же живут правила, без которых редактор молча ломает модуль. Логика
 * согласования спрашивает у шагов их назначение, поэтому проверяются назначения,
 * а не роли и не порядок: роли и порядок админ меняет свободно.
 *
 * Позиции нормализуются в 1..N, сохраняя группы: два шага с одинаковой позицией
 * так и останутся параллельными. Благодаря этому фронт может присылать любые
 * номера — хоть индексы групп, хоть прежние значения после вставки в середину.
 */
final class ApprovalRouteEditor
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PurchaseRouteTemplateRepository $templates,
    ) {
    }

    /**
     * Заменить заготовку присланными шагами.
     *
     * @param list<mixed> $steps сырые строки из запроса
     * @throws PurchaseRouteException
     */
    public function replace(PurchaseRequestKind $kind, array $steps, User $actor): PurchaseRouteTemplate
    {
        $parsed = [];
        foreach ($steps as $row) {
            $parsed[] = $this->parseStep($row);
        }

        $this->assertRulesHold($parsed);

        return $this->fill($this->templates->getOrCreate($kind), $parsed, $actor);
    }

    /**
     * @param list<PurchaseRouteTemplateStep> $steps
     */
    private function fill(PurchaseRouteTemplate $template, array $steps, User $actor): PurchaseRouteTemplate
    {
        foreach ($template->getSteps()->toArray() as $old) {
            $template->removeStep($old);
            $this->em->remove($old);
        }

        foreach ($this->normalizePositions($steps) as $step) {
            $template->addStep($step);
            $this->em->persist($step);
        }

        $template->setUpdatedBy($actor)->setUpdatedAt(new \DateTimeImmutable());

        $this->em->flush();

        return $template;
    }

    /**
     * Позиции в 1..N по возрастанию присланных: одинаковые остаются одинаковыми.
     *
     * @param list<PurchaseRouteTemplateStep> $steps
     * @return list<PurchaseRouteTemplateStep>
     */
    private function normalizePositions(array $steps): array
    {
        $groups = [];
        foreach ($steps as $step) {
            $groups[$step->getPosition()] = true;
        }

        $groups = array_keys($groups);
        sort($groups);

        /** @var array<int, int> $normalized */
        $normalized = [];
        foreach ($groups as $index => $position) {
            $normalized[$position] = $index + 1;
        }

        foreach ($steps as $step) {
            $step->setPosition($normalized[$step->getPosition()]);
        }

        usort(
            $steps,
            static fn (PurchaseRouteTemplateStep $a, PurchaseRouteTemplateStep $b): int
                => $a->getPosition() <=> $b->getPosition(),
        );

        return $steps;
    }

    /**
     * Правила заготовки. Каждое закрывает конкретную поломку, а не «на всякий случай»:
     *
     *   пустой маршрут не сохраняем — по нему заявка не пойдёт никуда, а «убрать
     *   маршрут» это не правка регламента, а его отмена;
     *
     *   слот замов не больше одного и обязательно позже разбора — иначе
     *   директору некуда назначать ответственных, а указатель маршрута поехал бы
     *   назад.
     *
     * Шаг ресёрча не требуется и не ограничивается по количеству: маршрут из
     * одних подписей законен. Модуль это переживает — исполнителя у заявки
     * просто не будет, поставщика и цены править будет негде, а кнопка возврата
     * документов в закупки не появится, потому что возвращать некуда.
     *
     * @param list<PurchaseRouteTemplateStep> $steps
     * @throws PurchaseRouteException
     */
    private function assertRulesHold(array $steps): void
    {
        if ($steps === []) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_EMPTY);
        }

        $slotPosition = null;
        $slots = 0;
        $firstTriage = null;

        foreach ($steps as $step) {
            if ($step->isApproversSlot()) {
                ++$slots;
                $slotPosition = $step->getPosition();

                continue;
            }
            if ($step->getPurpose() === PurchaseStepPurpose::TRIAGE
                && ($firstTriage === null || $step->getPosition() < $firstTriage)
            ) {
                $firstTriage = $step->getPosition();
            }
        }

        if ($slots > 1 || ($slotPosition !== null && ($firstTriage === null || $firstTriage >= $slotPosition))) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_APPROVERS_SLOT_INVALID);
        }
    }

    /**
     * Разобрать строку запроса в шаг заготовки.
     *
     * @throws PurchaseRouteException
     */
    private function parseStep(mixed $row): PurchaseRouteTemplateStep
    {
        if (!is_array($row) || !is_numeric($row['position'] ?? null)) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_STEP_INVALID);
        }

        $kind = PurchaseApproverKind::tryFrom((string) ($row['approverKind'] ?? PurchaseApproverKind::ROLE->value));
        if ($kind === null) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_STEP_INVALID);
        }

        $step = (new PurchaseRouteTemplateStep())
            ->setPosition((int) $row['position'])
            ->setApproverKind($kind)
            ->setTitle($this->parseTitle($row['title'] ?? null));

        // Слот замов — место, а не адрес: подписантов назначит директор, роль на
        // нём бессмысленна, а подписывают они как обычные согласанты.
        if ($kind === PurchaseApproverKind::USER) {
            return $step->setPurpose(PurchaseStepPurpose::SIGN_OFF);
        }

        // Ролью зама шаг не адресуют: такой шаг закрыл бы любой зам, а их
        // подбирают под заявку поимённо — для этого в маршруте стоит слот.
        $code = PurchaseRoleCode::tryFrom((string) ($row['roleCode'] ?? ''));
        if ($code === null || !in_array($code, PurchaseRoleCode::stepRoles(), true)) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_STEP_INVALID);
        }

        $purpose = PurchaseStepPurpose::tryFrom((string) ($row['purpose'] ?? PurchaseStepPurpose::SIGN_OFF->value));
        if ($purpose === null) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_STEP_INVALID);
        }

        return $step->setRoleCode($code)
            ->setPurpose($purpose)
            ->setRequiresFileType($this->parseFileType($row['requiresFileType'] ?? null));
    }

    /** @throws PurchaseRouteException */
    private function parseTitle(mixed $title): ?string
    {
        if ($title === null || $title === '') {
            return null;
        }
        if (!is_string($title) || mb_strlen(trim($title)) > 255) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_STEP_INVALID);
        }

        return $title;
    }

    /** @throws PurchaseRouteException */
    private function parseFileType(mixed $value): ?PurchaseFileType
    {
        if ($value === null || $value === '') {
            return null;
        }

        $type = PurchaseFileType::tryFrom((string) $value);
        if ($type === null) {
            throw new PurchaseRouteException(SpaApiError::PURCHASE_ROUTE_STEP_INVALID);
        }

        return $type;
    }
}
