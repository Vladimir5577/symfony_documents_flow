<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Analytics\AnalyticsBoardVersion;
use Doctrine\ORM\EntityManagerInterface;

final class PublishBoardVersionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @throws \RuntimeException
     */
    public function activate(AnalyticsBoardVersion $version): void
    {
        $board = $version->getBoard();
        if (!$board) {
            throw new \RuntimeException('Версия не привязана к доске.');
        }

        $board->setActiveVersion($version);
        $this->em->flush();
    }
}
