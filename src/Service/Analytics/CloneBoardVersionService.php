<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Analytics\AnalyticsBoard;
use App\Entity\Analytics\AnalyticsBoardVersion;
use App\Entity\Analytics\AnalyticsBoardVersionMetric;
use Doctrine\ORM\EntityManagerInterface;

final class CloneBoardVersionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Создаёт новую неактивную версию доски, копируя состав метрик из указанной версии.
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
        $this->em->persist($newVersion);

        // Фаза 1: копируем сами строки без parent (родителей ещё нет)
        $newByOldId = [];
        foreach ($sourceVersion->getVersionMetrics() as $sourceMetric) {
            $versionMetric = new AnalyticsBoardVersionMetric();
            $versionMetric->setBoardVersion($newVersion);
            $versionMetric->setMetric($sourceMetric->getMetric());
            $versionMetric->setPosition($sourceMetric->getPosition());
            $versionMetric->setIsRequired($sourceMetric->isRequired());
            $this->em->persist($versionMetric);
            $newByOldId[$sourceMetric->getId()] = $versionMetric;
        }

        // Фаза 2: проставляем parent по карте старый id → новая сущность
        foreach ($sourceVersion->getVersionMetrics() as $sourceMetric) {
            $oldParent = $sourceMetric->getParent();
            if (!$oldParent) {
                continue;
            }
            $newChild = $newByOldId[$sourceMetric->getId()] ?? null;
            $newParent = $newByOldId[$oldParent->getId()] ?? null;
            if ($newChild && $newParent) {
                $newChild->setParent($newParent);
            }
        }

        $this->em->flush();

        return $newVersion;
    }
}
