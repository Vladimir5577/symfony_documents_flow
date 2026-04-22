<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Analytics\AnalyticsMetric;
use App\Enum\Analytics\AnalyticsMetricAggregationType;
use App\Repository\Analytics\AnalyticsMetricRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final class AnalyticsMetricService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AnalyticsMetricRepository $repository,
    ) {
    }

    /**
     * @return AnalyticsMetric[]
     */
    public function findAll(): array
    {
        return $this->repository->findAll();
    }

    public function findById(int $id): ?AnalyticsMetric
    {
        return $this->repository->find($id);
    }

    /**
     * @throws \RuntimeException если business_key уже занят
     */
    public function create(
        string $businessKey,
        string $name,
        string $type,
        string $unit,
        AnalyticsMetricAggregationType $aggregationType,
        string|null $inputType,
        bool $isActive,
    ): AnalyticsMetric {
        $metric = new AnalyticsMetric();
        $metric->setBusinessKey(strtolower(trim($businessKey)));
        $metric->setName(trim($name));
        $metric->setType(trim($type));
        $metric->setUnit(trim($unit));
        $metric->setAggregationType($aggregationType);
        $metric->setInputType($inputType);
        $metric->setIsActive($isActive);

        try {
            $this->em->persist($metric);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw new \RuntimeException('Метрика с бизнес-ключом «' . $businessKey . '» уже существует.');
        }

        return $metric;
    }

    /**
     * @throws \RuntimeException если business_key уже занят другой метрикой
     */
    public function update(
        AnalyticsMetric $metric,
        string $businessKey,
        string $name,
        string $type,
        string $unit,
        AnalyticsMetricAggregationType $aggregationType,
        string|null $inputType,
        bool $isActive,
    ): void {
        $metric->setBusinessKey(strtolower(trim($businessKey)));
        $metric->setName(trim($name));
        $metric->setType(trim($type));
        $metric->setUnit(trim($unit));
        $metric->setAggregationType($aggregationType);
        $metric->setInputType($inputType);
        $metric->setIsActive($isActive);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw new \RuntimeException('Метрика с бизнес-ключом «' . $businessKey . '» уже существует.');
        }
    }

    public function delete(AnalyticsMetric $metric): void
    {
        $this->em->remove($metric);
        $this->em->flush();
    }
}
