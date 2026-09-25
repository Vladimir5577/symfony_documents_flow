<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRouteTemplate;
use App\Repository\Purchase\PurchaseRouteDefaultRepository;
use App\Repository\Purchase\PurchaseRouteTemplateRepository;

/**
 * Единственный ответ на вопрос «по какой заготовке пойдёт эта заявка».
 *
 * Один вход намеренно. Правило выбора будет меняться — сегодня это «назначенный
 * маршрут, иначе маршрут с порогом суммы, под который заявка подходит, иначе
 * дефолт по виду заявки», завтра спросят про категорию или филиал. Пока выбор спрашивают у резолвера, а не выводят на месте, такое правило
 * добавляется здесь и нигде больше: ни подача, ни контроллеры о нём не знают.
 *
 * Резолвер принимает заявку, а не вид заявки, по той же причине: измерения
 * добавляются без правки вызывающих.
 *
 * Умолчания в коде здесь нет: копия регламента, которую месяцами не
 * синхронизируют с админкой, — это второй ответ на вопрос «как согласуют
 * закупки», и однажды кто-то получит по ней маршрут, по которому давно не
 * работают. Поэтому ненастроенный маршрут — это не «возьмём типовой», а отказ
 * подать заявку.
 */
final class ApprovalRouteResolver
{
    public function __construct(
        private readonly PurchaseRouteTemplateRepository $templates,
        private readonly PurchaseRouteDefaultRepository $defaults,
    ) {
    }

    /**
     * Заготовка для этой заявки.
     *
     * @throws PurchaseTransitionException маршрут не настроен или назначенный не годится
     */
    public function resolve(PurchaseRequest $request): PurchaseRouteTemplate
    {
        $assigned = $request->getRouteTemplate();
        if ($assigned !== null) {
            $this->assertUsable($assigned, $request);

            return $assigned;
        }

        // Ускоренная процедура: заявка дешевле порога маршрута идёт им сама.
        $byAmount = $this->byAmount($request);
        if ($byAmount !== null) {
            return $byAmount;
        }

        $default = $this->defaults->findByKind($request->getCreatedAs())?->getTemplate();
        if ($default === null) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_ROUTE_NOT_CONFIGURED);
        }

        // Дефолт мог быть назначен, а заготовку потом выключили или опустошили.
        // Молча подменять её другой нельзя: «как согласуют закупки» — не то
        // решение, которое сервер принимает за админа.
        $this->assertUsable($default, $request);

        return $default;
    }

    /**
     * Из чего разбирающий выбирает маршрут для этой заявки.
     *
     * @return list<PurchaseRouteTemplate>
     */
    public function options(PurchaseRequest $request): array
    {
        $total = $request->getTotalAmountKopecks();

        return array_values(array_filter(
            $this->templates->findActiveForKind($request->getCreatedAs()),
            static fn (PurchaseRouteTemplate $template): bool => $template->fitsAmount($total),
        ));
    }

    /**
     * Заготовку можно применить к этой заявке.
     *
     * Порог суммы — тоже условие: дорогую заявку ускоренным маршрутом не пустить
     * ни дефолтом, ни назначением на разборе, иначе она прошла бы мимо директора.
     */
    public function isUsable(PurchaseRouteTemplate $template, PurchaseRequest $request): bool
    {
        return $template->isActive()
            && !$template->isEmpty()
            && $template->allowsKind($request->getCreatedAs())
            && $template->fitsAmount($request->getTotalAmountKopecks());
    }

    /**
     * Маршрут с порогом суммы, под который заявка подходит; из нескольких —
     * с самым низким порогом: он и есть самая короткая процедура для этой суммы.
     */
    private function byAmount(PurchaseRequest $request): ?PurchaseRouteTemplate
    {
        $best = null;
        foreach ($this->templates->findActiveForKind($request->getCreatedAs()) as $template) {
            $limit = $template->getMaxAmountKopecks();
            if ($limit === null || !$this->isUsable($template, $request)) {
                continue;
            }
            if ($best === null || $limit < (int) $best->getMaxAmountKopecks()) {
                $best = $template;
            }
        }

        return $best;
    }

    /** @throws PurchaseTransitionException */
    private function assertUsable(PurchaseRouteTemplate $template, PurchaseRequest $request): void
    {
        if (!$this->isUsable($template, $request)) {
            throw new PurchaseTransitionException(SpaApiError::PURCHASE_ROUTE_NOT_CONFIGURED);
        }
    }
}
