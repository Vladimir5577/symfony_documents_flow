<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Analytics\AnalyticsOrganization;
use App\Entity\Organization\AbstractOrganization;
use App\Repository\Analytics\AnalyticsOrganizationRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final class AnalyticsOrganizationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AnalyticsOrganizationRepository $repository,
    ) {
    }

    /**
     * @return AnalyticsOrganization[]
     */
    public function findAll(): array
    {
        return $this->repository->findBy([], ['sortOrder' => 'ASC']);
    }

    public function findById(int $id): ?AnalyticsOrganization
    {
        return $this->repository->find($id);
    }

    /**
     * @throws \RuntimeException если организация уже добавлена
     */
    public function create(
        AbstractOrganization $organization,
        int $sortOrder,
        bool $isVisible,
    ): AnalyticsOrganization {
        $analyticsOrg = new AnalyticsOrganization();
        $analyticsOrg->setOrganization($organization);
        $analyticsOrg->setSortOrder($sortOrder);
        $analyticsOrg->setIsVisible($isVisible);

        try {
            $this->em->persist($analyticsOrg);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw new \RuntimeException('Эта организация уже добавлена в аналитику.');
        }

        return $analyticsOrg;
    }

    public function update(
        AnalyticsOrganization $analyticsOrg,
        AbstractOrganization $organization,
        int $sortOrder,
        bool $isVisible,
    ): void {
        $analyticsOrg->setOrganization($organization);
        $analyticsOrg->setSortOrder($sortOrder);
        $analyticsOrg->setIsVisible($isVisible);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw new \RuntimeException('Эта организация уже добавлена в аналитику.');
        }
    }

    public function delete(AnalyticsOrganization $analyticsOrg): void
    {
        $this->em->remove($analyticsOrg);
        $this->em->flush();
    }
}
