<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Analytics\AnalyticsReport;
use App\Entity\User\User;
use Symfony\Bundle\SecurityBundle\Security;

final class AnalyticsReportAccess
{
    public function __construct(private readonly Security $security)
    {
    }

    /** @return int[]|null null — полный доступ; [] — нет доступных досок. */
    public function getAllowedRoleIds(): ?array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        if ($this->security->isGranted('ROLE_ADMIN') || $this->security->isGranted('ROLE_ANALYTIC')) {
            return null;
        }

        $ids = [];
        foreach ($user->getRolesRel() as $userRole) {
            $roleId = $userRole->getRole()?->getId();
            if ($roleId !== null) {
                $ids[] = $roleId;
            }
        }

        return array_values(array_unique($ids));
    }

    public function canAccessReport(AnalyticsReport $report): bool
    {
        $roleIds = $this->getAllowedRoleIds();

        return $roleIds === null || in_array($report->getBoard()?->getBelongsToRole()?->getId(), $roleIds, true);
    }
}
