<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Analytics\AnalyticsBoardVersion;
use App\Enum\Analytics\AnalyticsBoardVersionStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Публикация версии: старая published -> archived, новая -> published.
 * Всё в одной транзакции, чтобы не было окна «две published».
 */
final class PublishBoardVersionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @throws \RuntimeException
     */
    public function publish(AnalyticsBoardVersion $version): void
    {
        if ($version->getStatus() !== AnalyticsBoardVersionStatus::Draft) {
            throw new \RuntimeException('Опубликовать можно только draft-версию.');
        }

        $board = $version->getBoard();
        if (!$board) {
            throw new \RuntimeException('Версия не привязана к доске.');
        }

        $this->em->beginTransaction();
        try {
            // Помечаем текущую published как archived
            foreach ($board->getBoardVersions() as $v) {
                if ($v->getStatus() === AnalyticsBoardVersionStatus::Published
                    && $v->getId() !== $version->getId()
                ) {
                    $v->setStatus(AnalyticsBoardVersionStatus::Archived);
                    $this->em->persist($v);
                }
            }

            // Публикуем новую версию
            $version->setStatus(AnalyticsBoardVersionStatus::Published);
            $this->em->persist($version);

            $this->em->flush();
            $this->em->commit();
        } catch (\Throwable $e) {
            $this->em->rollBack();
            throw $e;
        }
    }
}
