<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseApprovalStep;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRouteTemplate;
use App\Entity\Purchase\PurchaseRouteTemplateStep;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseApproverKind;
use App\Enum\Purchase\PurchaseRequestKind;
use App\Repository\Purchase\PurchaseRouteTemplateRepository;
use App\Repository\Purchase\PurchaseSettingRepository;
use App\Enum\User\UserRole;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Сборка маршрута согласования заявки из шаблона.
 *
 * Шаблон выбирается КНОПКОЙ создания (created_as), а не суммой. Подбора по
 * диапазонам и молчаливого fallback'а нет: нет активного шаблона — 409, потому
 * что тихо подставить короткий маршрут на денежном пути хуже, чем упасть.
 *
 * Маршрут — снапшот: правка шаблона не трогает заявки в пути. Повторная подача
 * после возврата строит его заново с нуля.
 */
final class ApprovalRouteBuilder
{
    public function __construct(
        private readonly PurchaseRouteTemplateRepository $templates,
        private readonly PurchaseSettingRepository $settings,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Построить маршрут заявки. Существующие шаги сносятся целиком.
     * Flush — на вызывающей стороне.
     */
    public function build(PurchaseRequest $request): void
    {
        $template = $this->templates->findDefaultFor($request->getCreatedAs());
        if ($template === null) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_ROUTE_TEMPLATE_MISSING);
        }

        $request->setRouteTemplate($template);
        $this->applyTemplate($request, $template);
    }

    /**
     * Переложить заявку на другой шаблон (переключатель у отдела закупок).
     * Уже принятые решения при этом теряются — вызывать только пока маршрут
     * стоит на первом шаге отдела закупок, это стережёт PurchaseRequestService.
     */
    public function applyTemplate(PurchaseRequest $request, PurchaseRouteTemplate $template): void
    {
        foreach ($request->getSteps()->toArray() as $step) {
            $request->removeStep($step);
            $this->em->remove($step);
        }

        foreach ($template->getSteps() as $templateStep) {
            foreach ($this->expand($request, $templateStep) as $step) {
                $request->addStep($step);
                $this->em->persist($step);
            }
        }

        $this->ensureDirector($request);
        $this->dedupeByUser($request);
    }

    /**
     * Обязательный минимум поверх любого шаблона: от порога директора его шаг
     * присутствует всегда и удалить его нельзя (двигать — можно).
     *
     * Встаёт сразу после первого шага отдела закупок, а не в конец: подпись
     * директора — санкция на трату. Стой она последней, договор бы уже готовили,
     * а финдиректор смотрел документы по закупке, которую могут не разрешить.
     */
    private function ensureDirector(PurchaseRequest $request): void
    {
        if ($request->getTotalAmount() < $this->settings->getCeoApproveMinAmount()) {
            return;
        }

        foreach ($request->getSteps() as $step) {
            if ($step->getApproverRole() === UserRole::ROLE_PURCHASE_DIRECTOR->value) {
                $step->setMandatory(true);

                return;
            }
        }

        $position = $this->firstPositionOfRole($request, UserRole::ROLE_PURCHASE_DEPARTMENT->value) + 1;
        $this->shiftFrom($request, $position);

        $step = (new PurchaseApprovalStep())
            ->setPosition($position)
            ->setApproverKind(PurchaseApproverKind::ROLE)
            ->setApproverRole(UserRole::ROLE_PURCHASE_DIRECTOR->value)
            ->setTitle('Согласование директора')
            ->setMandatory(true);

        $request->addStep($step);
        $this->em->persist($step);
    }

    /**
     * Один человек попал на два этапа — оставляем поздний: он старше по смыслу.
     * Оставили бы ранний — подписал бы раньше тех, после кого должен идти.
     * Ролевые шаги не трогаем: отдел закупок в маршруте стоит дважды намеренно.
     */
    private function dedupeByUser(PurchaseRequest $request): void
    {
        /** @var array<int, PurchaseApprovalStep> $latest */
        $latest = [];
        foreach ($request->getSteps() as $step) {
            $userId = $step->getApproverUser()?->getId();
            if ($userId === null) {
                continue;
            }
            if (!isset($latest[$userId]) || $step->getPosition() > $latest[$userId]->getPosition()) {
                $latest[$userId] = $step;
            }
        }

        foreach ($request->getSteps()->toArray() as $step) {
            $userId = $step->getApproverUser()?->getId();
            if ($userId !== null && $latest[$userId] !== $step) {
                $request->removeStep($step);
                $this->em->remove($step);
            }
        }
    }

    /**
     * Развернуть шаг шаблона в шаги заявки.
     * ROLE и USER дают одну строку, CATEGORY_RESPONSIBLE — от нуля до многих.
     *
     * @return list<PurchaseApprovalStep>
     */
    private function expand(PurchaseRequest $request, PurchaseRouteTemplateStep $tpl): array
    {
        $blank = static fn (): PurchaseApprovalStep => (new PurchaseApprovalStep())
            ->setPosition($tpl->getPosition())
            ->setTitle($tpl->getTitle())
            ->setRequiresFileType($tpl->getRequiresFileType());

        return match ($tpl->getApproverKind()) {
            PurchaseApproverKind::ROLE => $tpl->getApproverRole() === null ? [] : [
                $blank()
                    ->setApproverKind(PurchaseApproverKind::ROLE)
                    ->setApproverRole($tpl->getApproverRole()),
            ],
            PurchaseApproverKind::USER => $tpl->getApproverUser() === null ? [] : [
                $blank()
                    ->setApproverKind(PurchaseApproverKind::USER)
                    ->setApproverUser($tpl->getApproverUser()),
            ],
            PurchaseApproverKind::CATEGORY_RESPONSIBLE => array_map(
                static fn (User $user): PurchaseApprovalStep => $blank()
                    ->setApproverKind(PurchaseApproverKind::USER)
                    ->setApproverUser($user),
                $this->collectResponsibles($request),
            ),
        };
    }

    /**
     * Ответственные за категорию шапки и за категории позиций, минус автор:
     * сам себе согласантом человек не бывает.
     *
     * @return list<User>
     */
    private function collectResponsibles(PurchaseRequest $request): array
    {
        $authorId = $request->getCreatedBy()?->getId();
        $byId = [];

        $headerResponsible = $request->getCategory()?->getResponsibleUser();
        if ($headerResponsible !== null) {
            $byId[$headerResponsible->getId()] = $headerResponsible;
        }

        foreach ($request->getItems() as $item) {
            $responsible = $item->getCategoryItem()?->getCategory()?->getResponsibleUser();
            if ($responsible !== null) {
                $byId[$responsible->getId()] = $responsible;
            }
        }

        unset($byId[$authorId]);

        return array_values($byId);
    }

    /** Минимальная позиция шага с этой ролью; 0 — такого шага нет. */
    private function firstPositionOfRole(PurchaseRequest $request, string $role): int
    {
        $min = null;
        foreach ($request->getSteps() as $step) {
            if ($step->getApproverRole() === $role && ($min === null || $step->getPosition() < $min)) {
                $min = $step->getPosition();
            }
        }

        return $min ?? 0;
    }

    private function shiftFrom(PurchaseRequest $request, int $from): void
    {
        foreach ($request->getSteps() as $step) {
            if ($step->getPosition() >= $from) {
                $step->setPosition($step->getPosition() + 1);
            }
        }
    }

    /**
     * Превью маршрута для формы создания: какие шаги получатся при выбранной
     * кнопке и такой сумме. Считает бэк, чтобы фронт не дублировал эти правила.
     *
     * @return list<array{position: int, title: string}>
     */
    public function preview(PurchaseRequestKind $kind, float $amount): array
    {
        $template = $this->templates->findDefaultFor($kind);
        if ($template === null) {
            return [];
        }

        $rows = [];
        foreach ($template->getSteps() as $step) {
            $rows[] = ['position' => $step->getPosition(), 'title' => $this->describe($step)];
        }

        if ($amount >= $this->settings->getCeoApproveMinAmount()
            && !$this->templateHasRole($template, UserRole::ROLE_PURCHASE_DIRECTOR->value)
        ) {
            $after = 0;
            foreach ($template->getSteps() as $step) {
                if ($step->getApproverRole() === UserRole::ROLE_PURCHASE_DEPARTMENT->value) {
                    $after = $step->getPosition();
                    break;
                }
            }
            $rows[] = ['position' => $after + 1, 'title' => 'Согласование директора'];
        }

        usort($rows, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return $rows;
    }

    private function templateHasRole(PurchaseRouteTemplate $template, string $role): bool
    {
        foreach ($template->getSteps() as $step) {
            if ($step->getApproverRole() === $role) {
                return true;
            }
        }

        return false;
    }

    private function describe(PurchaseRouteTemplateStep $step): string
    {
        if ($step->getTitle() !== null && $step->getTitle() !== '') {
            return $step->getTitle();
        }

        return match ($step->getApproverKind()) {
            PurchaseApproverKind::ROLE => UserRole::tryFrom((string) $step->getApproverRole())?->getLabel()
                ?? (string) $step->getApproverRole(),
            PurchaseApproverKind::USER => trim(sprintf(
                '%s %s',
                $step->getApproverUser()?->getLastname() ?? '',
                $step->getApproverUser()?->getFirstname() ?? '',
            )) ?: 'Сотрудник',
            PurchaseApproverKind::CATEGORY_RESPONSIBLE => 'Ответственные за категорию',
        };
    }
}
