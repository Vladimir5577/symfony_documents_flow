<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Analytics\AnalyticsBoard;
use App\Entity\Analytics\AnalyticsOrganizationBoard;
use App\Entity\Organization\AbstractOrganization;
use App\Repository\Analytics\AnalyticsOrganizationBoardRepository;
use Doctrine\ORM\EntityManagerInterface;

final class OrganizationBoardService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AnalyticsOrganizationBoardRepository $repository,
    ) {
    }

    /**
     * @return AnalyticsOrganizationBoard[]
     */
    public function findAll(): array
    {
        return $this->repository->findAll();
    }

    /**
     * @return AnalyticsOrganizationBoard[]
     */
    public function findByOrganization(AbstractOrganization $organization): array
    {
        return $this->repository->findBy(['organization' => $organization]);
    }

    public function findById(int $id): ?AnalyticsOrganizationBoard
    {
        return $this->repository->find($id);
    }

    /**
     * Назначить доску организации.
     * @throws \RuntimeException если уже назначена.
     */
    public function create(AbstractOrganization $organization, AnalyticsBoard $board, bool $isRequired): AnalyticsOrganizationBoard
    {
        $existing = $this->repository->findOneBy([
            'organization' => $organization,
            'board' => $board,
        ]);
        if ($existing) {
            throw new \RuntimeException('Доска «' . $board->getName() . '» уже назначена этой организации.');
        }

        $orgBoard = new AnalyticsOrganizationBoard();
        $orgBoard->setOrganization($organization);
        $orgBoard->setBoard($board);
        $orgBoard->setIsRequired($isRequired);

        $this->em->persist($orgBoard);
        $this->em->flush();

        return $orgBoard;
    }

    public function toggleRequired(AnalyticsOrganizationBoard $orgBoard): void
    {
        $orgBoard->setIsRequired(!$orgBoard->isRequired());
        $this->em->flush();
    }

    public function delete(AnalyticsOrganizationBoard $orgBoard): void
    {
        $this->em->remove($orgBoard);
        $this->em->flush();
    }
}
