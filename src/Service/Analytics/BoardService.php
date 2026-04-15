<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Analytics\AnalyticsBoard;
use App\Entity\Analytics\AnalyticsBoardVersion;
use App\Entity\Analytics\AnalyticsBoardVersionMetric;
use App\Enum\Analytics\AnalyticsPeriodType;
use App\Repository\Analytics\AnalyticsBoardRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final class BoardService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AnalyticsBoardRepository $boardRepository,
    ) {
    }

    /**
     * @return AnalyticsBoard[]
     */
    public function findAll(): array
    {
        return $this->boardRepository->findAll();
    }

    public function findById(int $id): ?AnalyticsBoard
    {
        return $this->boardRepository->find($id);
    }

    /**
     * @throws \RuntimeException если имя занято.
     */
    public function create(string $name, ?string $description, ?AnalyticsPeriodType $periodType = null): AnalyticsBoard
    {
        $board = new AnalyticsBoard();
        $board->setName(trim($name));
        $board->setDescription(trim($description ?? ''));
        if ($periodType !== null) {
            $board->setPeriodType($periodType);
        }

        try {
            $this->em->persist($board);
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            throw new \RuntimeException('Доска с именем «' . $name . '» уже существует.', 0, $e);
        }

        // Создаём первую draft-версию
        $version = new AnalyticsBoardVersion();
        $version->setBoard($board);
        $version->setVersionNumber(1);
        // status = Draft по умолчанию в конструкторе
        $this->em->persist($version);
        $this->em->flush();

        return $board;
    }

    /**
     * @throws \RuntimeException
     */
    public function update(AnalyticsBoard $board, string $name, ?string $description): void
    {
        $board->setName(trim($name));
        $board->setDescription(trim($description ?? ''));

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            throw new \RuntimeException('Доска с именем «' . $name . '» уже существует.', 0, $e);
        }
    }

    public function delete(AnalyticsBoard $board): void
    {
        $this->em->remove($board);
        $this->em->flush();
    }

    /**
     * Получить первую draft-версию доски (если есть).
     */
    public function findDraftVersion(AnalyticsBoard $board): ?AnalyticsBoardVersion
    {
        foreach ($board->getBoardVersions() as $version) {
            if ($version->getStatus() === \App\Enum\Analytics\AnalyticsBoardVersionStatus::Draft) {
                return $version;
            }
        }

        return null;
    }

    public function removeVersionMetric(\App\Entity\Analytics\AnalyticsBoardVersionMetric $vm): void
    {
        // Отвязываем от коллекции, чтобы orphanRemoval сработал при flush
        $version = $vm->getBoardVersion();
        if ($version) {
            $version->removeVersionMetric($vm);
        }
    }

    public function removeVersion(AnalyticsBoardVersion $version): void
    {
        $this->em->remove($version);
    }

    public function flush(): void
    {
        $this->em->flush();
    }
}
