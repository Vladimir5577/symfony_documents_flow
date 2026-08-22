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
 * КАКАЯ роль стоит на задаче маршрута (purchase_approval_task.role_code). Это
 * данные, они меняются каждый день и живут в админке.
 *
 * Гейты по конкретным кодам не ветвятся: право спрашивают у полномочий
 * (PurchaseCapability), адресность задачи — у кода роли на ней. Код знает коды
 * только в двух местах: здесь и в фикстурах маршрутов.
 */
enum PurchaseRoleCode: string
{
    case DIRECTOR = 'DIRECTOR';
    case PURCHASE_DEPARTMENT = 'PURCHASE_DEPARTMENT';
    case ACCOUNTING = 'ACCOUNTING';
    case LEGAL = 'LEGAL';
    case FINANCE_DIRECTOR = 'FINANCE_DIRECTOR';

    /**
     * Профильный зам — роль, которой задачу не адресуют, но которая служит пулом.
     *
     * Остальные роли стоят в маршруте адресатом: задача адресована роли, и
     * закрывает её любой носитель. Замов же на каждую заявку выбирает
     * разбирающий из тех, кому роль выдана, — поэтому в маршруте стоит
     * динамический этап с этой ролью в качестве пула. Адресовать ей задачу
     * значило бы «подпишет любой зам», тогда как смысл ровно обратный.
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
     * Роли, которыми можно адресовать задачу маршрута — всё, кроме замов.
     *
     * Задачу, адресованную роли, закрывает любой её носитель. Для замов это
     * означало бы «подписывает любой зам», тогда как их смысл ровно обратный:
     * разбирающий выбирает конкретных под конкретную заявку. Поэтому в редакторе
     * маршрутов эта роль предлагается не как адресат, а как пул.
     *
     * @return list<self>
     */
    public static function taskRoles(): array
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
