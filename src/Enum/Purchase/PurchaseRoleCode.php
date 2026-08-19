<?php

declare(strict_types=1);

namespace App\Enum\Purchase;

/**
 * Роли модуля закупок: кто бывает в маршруте и что каждому можно вне него.
 *
 * Единственный источник правды по составу ролей. Справочника в базе нет
 * намеренно: роль — это набор прав, а права в этом модуле меняются вместе с
 * регламентом, то есть деплоем. Таблица с CRUD-экраном отвечала бы на вопрос
 * «дайте админу придумать новую роль», которого никто не задавал, а платить за
 * неё пришлось бы сущностью, репозиторием, командой установки и вечным
 * вопросом «а роли-то заведены?».
 *
 * Что при этом остаётся в базе: КОМУ роль выдана (purchase_approver_role) и
 * КАКАЯ роль стоит на шаге маршрута (purchase_approval_step.role_code). Это
 * данные, они меняются каждый день и живут в админке.
 *
 * Гейты по конкретным кодам не ветвятся: право спрашивают у полномочий
 * (PurchaseCapability), адресность шага — у кода роли на нём. Код знает коды
 * только в двух местах: здесь и в строителе дефолтного маршрута.
 */
enum PurchaseRoleCode: string
{
    case DIRECTOR = 'DIRECTOR';
    case PURCHASE_DEPARTMENT = 'PURCHASE_DEPARTMENT';
    case ACCOUNTING = 'ACCOUNTING';
    case LEGAL = 'LEGAL';
    case FINANCE_DIRECTOR = 'FINANCE_DIRECTOR';

    /**
     * Профильный зам — единственная роль, которая шаг не занимает.
     *
     * Остальные роли стоят в маршруте: шаг адресован роли, и подписывает его
     * любой её носитель. Замов же на каждую заявку выбирает директор из тех, кому
     * эта роль выдана, — маршрут только резервирует под них позицию. Поэтому
     * ставить эту роль на шаг маршрута смысла нет: подписантами станут те, кого
     * отметили, а не все замы разом.
     */
    case PROFILE_DEPUTY = 'PROFILE_DEPUTY';

    public function getLabel(): string
    {
        return match ($this) {
            self::DIRECTOR => 'Директор',
            self::PURCHASE_DEPARTMENT => 'Отдел закупок',
            self::ACCOUNTING => 'Бухгалтерия',
            self::LEGAL => 'Юристы',
            self::FINANCE_DIRECTOR => 'Финансовый директор',
            self::PROFILE_DEPUTY => 'Профильный зам',
        };
    }

    /**
     * Что роль позволяет вне маршрута.
     *
     * Бухгалтерия, юристы и замы пусты намеренно: они только подписывают свой
     * шаг, а заявку видят как участники маршрута — на это полномочие не нужно.
     *
     * @return list<PurchaseCapability>
     */
    public function getCapabilities(): array
    {
        return match ($this) {
            self::DIRECTOR => [
                PurchaseCapability::VIEW_ALL,
                PurchaseCapability::SUPERVISE,
            ],
            self::PURCHASE_DEPARTMENT => [
                PurchaseCapability::VIEW_ALL,
                PurchaseCapability::MANAGE_DICTIONARIES,
                PurchaseCapability::RUN_EXECUTION,
            ],
            self::FINANCE_DIRECTOR => [
                PurchaseCapability::VIEW_ALL,
                PurchaseCapability::RUN_EXECUTION,
            ],
            self::ACCOUNTING, self::LEGAL, self::PROFILE_DEPUTY => [],
        };
    }

    public function hasCapability(PurchaseCapability $capability): bool
    {
        return in_array($capability, $this->getCapabilities(), true);
    }

    /**
     * Роли, которыми можно адресовать шаг маршрута — всё, кроме замов.
     *
     * Шаг, адресованный роли, закрывает любой её носитель. Для замов это
     * означало бы «подписывает любой зам», тогда как их смысл ровно обратный:
     * директор выбирает конкретных под конкретную заявку, а маршрут держит для
     * них слот. Поэтому в редакторе маршрутов эта роль не предлагается.
     *
     * @return list<self>
     */
    public static function stepRoles(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $code): bool => $code !== self::PROFILE_DEPUTY,
        ));
    }

    /**
     * Роли, у которых есть это полномочие — кому уходят уведомления «модулю».
     *
     * @return list<self>
     */
    public static function withCapability(PurchaseCapability $capability): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $code): bool => $code->hasCapability($capability),
        ));
    }
}
