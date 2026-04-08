<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Analytics\AnalyticsBoard;
use App\Entity\Analytics\AnalyticsBoardVersion;
use App\Entity\Analytics\AnalyticsBoardVersionMetric;
use App\Enum\Analytics\AnalyticsBoardVersionStatus;
use Doctrine\ORM\EntityManagerInterface;

final class CloneBoardVersionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Создаёт новую draft-версию доски, копируя состав метрик из указанной версии.
     */
    public function cloneFromVersion(AnalyticsBoardVersion $sourceVersion): AnalyticsBoardVersion
    {
        $board = $sourceVersion->getBoard();
        if (!$board) {
            throw new \RuntimeException('Исходная версия не привязана к доске.');
        }

        // Определяем следующий номер версии
        $maxVersion = 0;
        foreach ($board->getBoardVersions() as $v) {
            if ($v->getVersionNumber() > $maxVersion) {
                $maxVersion = $v->getVersionNumber();
            }
        }

        $newVersion = new AnalyticsBoardVersion();
        $newVersion->setBoard($board);
        $newVersion->setVersionNumber($maxVersion + 1);
        // Status = Draft (по умолчанию в конструкторе)
        $this->em->persist($newVersion);

        // Копируем состав метрик
        foreach ($sourceVersion->getVersionMetrics() as $sourceMetric) {
            $versionMetric = new AnalyticsBoardVersionMetric();
            $versionMetric->setBoardVersion($newVersion);
            $versionMetric->setMetric($sourceMetric->getMetric());
            $versionMetric->setPosition($sourceMetric->getPosition());
            $versionMetric->setIsRequired($sourceMetric->isRequired());
            $this->em->persist($versionMetric);
        }

        $this->em->flush();

        return $newVersion;
    }
}
