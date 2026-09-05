<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Analytics;

use App\Entity\User\User;

/**
 * Скоуп аналитики по организации.
 *
 * Профильная роль (ROLE_FINANCE, ROLE_HR, …) даёт право на свой раздел, но не на
 * весь холдинг: раньше org_id=0 разворачивал все головные организации, а любой
 * org_id принимался как есть. Теперь пользователь без ROLE_ANALYTIC/ROLE_ADMIN
 * видит только своё юрлицо (корневую организацию с дочерними), а переданный
 * org_id для него игнорируется.
 */
trait AnalyticsOrganizationScopeTrait
{
    /**
     * @return int|null id организации для сервиса; null — показывать нечего
     *                  (сотрудник без организации и без права на весь холдинг)
     */
    private function scopeOrganizationId(int $requestedOrgId): ?int
    {
        if ($this->isGranted('ROLE_ANALYTIC')) {
            return $requestedOrgId;
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return null;
        }

        $organization = $user->getOrganization();
        if ($organization === null) {
            return null;
        }

        return $organization->getRootOrganization()->getId();
    }
}
