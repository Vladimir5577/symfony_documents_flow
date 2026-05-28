<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Entity\Analytics\AnalyticsBoard;
use App\Entity\Analytics\AnalyticsBoardVersion;
use App\Entity\Analytics\AnalyticsBoardVersionMetric;
use App\Entity\Analytics\AnalyticsMetric;
use App\Entity\Analytics\AnalyticsReportValue;
use App\Enum\Analytics\AnalyticsCategory;
use App\Enum\Analytics\AnalyticsPeriodType;
use App\Repository\Analytics\AnalyticsBoardRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
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
    public function create(
        string $name,
        ?string $description,
        AnalyticsCategory $category,
        ?AnalyticsPeriodType $periodType = null,
    ): AnalyticsBoard {
        $board = new AnalyticsBoard();
        $board->setName(trim($name));
        $board->setDescription(trim($description ?? ''));
        $board->setCategory($category);
        if ($periodType !== null) {
            $board->setPeriodType($periodType);
        }

        try {
            $this->em->persist($board);
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            throw new \RuntimeException('Доска с именем «' . $name . '» уже существует.', 0, $e);
        }

        $version = new AnalyticsBoardVersion();
        $version->setBoard($board);
        $version->setVersionNumber(1);
        $this->em->persist($version);
        $this->em->flush();

        $board->setActiveVersion($version);
        $this->em->flush();

        return $board;
    }

    /**
     * @throws \RuntimeException
     */
    public function update(
        AnalyticsBoard $board,
        string $name,
        ?string $description,
        ?AnalyticsPeriodType $periodType = null,
        ?AnalyticsCategory $category = null,
    ): void {
        $board->setName(trim($name));
        $board->setDescription(trim($description ?? ''));

        if ($periodType !== null && $periodType !== $board->getPeriodType()) {
            if (!$this->canChangePeriodType($board)) {
                throw new \RuntimeException(
                    'Нельзя сменить частоту отчётов: по доске уже есть отчёты.',
                );
            }
            $board->setPeriodType($periodType);
        }

        if ($category !== null && $category !== $board->getCategory()) {
            if (!$this->canChangeCategory($board)) {
                throw new \RuntimeException(
                    'Категорию доски нельзя менять: уже есть метрики или отчёты. '
                    . 'Создайте новую доску нужной категории.',
                );
            }
            $board->setCategory($category);
        }

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            throw new \RuntimeException('Доска с именем «' . $name . '» уже существует.', 0, $e);
        }
    }

    /**
     * Можно ли сменить частоту отчётов: пока ни по одной версии нет отчётов.
     * Метрики на доске сами по себе не мешают — они не привязаны к periodType.
     */
    public function canChangePeriodType(AnalyticsBoard $board): bool
    {
        return !$this->hasAnyReports($board);
    }

    public function hasAnyReports(AnalyticsBoard $board): bool
    {
        foreach ($board->getBoardVersions() as $version) {
            if ($this->hasReportsForVersion($version)) {
                return true;
            }
        }

        return false;
    }

    public function delete(AnalyticsBoard $board): void
    {
        $this->em->remove($board);
        $this->em->flush();
    }

    /**
     * Можно ли сменить категорию доски: пока на доске нет ни одной метрики
     * ни в одной версии и нет ни одного отчёта.
     */
    public function canChangeCategory(AnalyticsBoard $board): bool
    {
        foreach ($board->getBoardVersions() as $version) {
            if ($version->getVersionMetrics()->count() > 0) {
                return false;
            }
            if ($this->hasReportsForVersion($version)) {
                return false;
            }
        }

        return true;
    }

    public function removeVersionMetric(AnalyticsBoardVersionMetric $vm): void
    {
        // Отвязываем от коллекции, чтобы orphanRemoval сработал при flush
        $version = $vm->getBoardVersion();
        if ($version) {
            $version->removeVersionMetric($vm);
        }
    }

    public function hasReportValuesForVersionMetric(AnalyticsBoardVersionMetric $vm): bool
    {
        return $this->em->getRepository(AnalyticsReportValue::class)->count([
            'boardVersionMetric' => $vm,
        ]) > 0;
    }

    /**
     * Обновляет состав метрик версии без пересоздания строк (сохраняет id для analytics_report_values).
     *
     * @param array<int|string, array{enabled?: string, is_required?: string, parent_metric_id?: string}> $selectedMetrics данные формы metrics[metricId]
     *
     * @throws \RuntimeException
     */
    public function syncVersionMetrics(AnalyticsBoardVersion $version, array $selectedMetrics, iterable $allMetricsById): void
    {
        $boardCategory = $version->getBoard()?->getCategory();
        if ($boardCategory === null) {
            throw new \RuntimeException('У доски не задана категория — нельзя редактировать состав метрик.');
        }

        $metricsById = [];
        foreach ($allMetricsById as $metric) {
            if ($metric instanceof AnalyticsMetric && $metric->getId() !== null) {
                $metricsById[$metric->getId()] = $metric;
            }
        }

        /** @var array<int, AnalyticsBoardVersionMetric> $existingByMetricId */
        $existingByMetricId = [];
        foreach ($version->getVersionMetrics() as $vm) {
            $metricId = $vm->getMetric()?->getId();
            if ($metricId !== null) {
                $existingByMetricId[$metricId] = $vm;
            }
        }

        $enabledMetricIds = [];
        /** @var list<array{metricId: int, metric: AnalyticsMetric, data: array}> $enabledItems */
        $enabledItems = [];
        /** @var array<int, AnalyticsBoardVersionMetric> $vmByMetricId */
        $vmByMetricId = [];

        foreach ($selectedMetrics as $metricIdStr => $data) {
            if (empty($data['enabled'])) {
                continue;
            }
            $metricId = (int) $metricIdStr;
            $metric = $metricsById[$metricId] ?? null;
            if (!$metric) {
                continue;
            }

            if ($metric->getCategory() !== $boardCategory) {
                throw new \RuntimeException(sprintf(
                    'Нельзя добавить метрику «%s» (%s) на доску категории «%s».',
                    $metric->getName() ?? (string) $metricId,
                    $metric->getCategory()?->label() ?? '—',
                    $boardCategory->label(),
                ));
            }

            $enabledMetricIds[$metricId] = true;
            $enabledItems[] = [
                'metricId' => $metricId,
                'metric' => $metric,
                'data' => $data,
            ];
        }

        usort(
            $enabledItems,
            static fn (array $a, array $b): int => ((int) ($a['data']['position'] ?? 0)) <=> ((int) ($b['data']['position'] ?? 0)),
        );

        $position = 1;
        foreach ($enabledItems as $item) {
            $metricId = $item['metricId'];
            $metric = $item['metric'];
            $data = $item['data'];

            if (isset($existingByMetricId[$metricId])) {
                $vm = $existingByMetricId[$metricId];
            } else {
                $vm = new AnalyticsBoardVersionMetric();
                $vm->setBoardVersion($version);
                $vm->setMetric($metric);
                $version->addVersionMetric($vm);
            }

            $vm->setPosition($position++);
            $vm->setIsRequired(!empty($data['is_required']));
            $vm->setParent(null);
            $vmByMetricId[$metricId] = $vm;
        }

        foreach ($existingByMetricId as $metricId => $vm) {
            if (isset($enabledMetricIds[$metricId])) {
                continue;
            }
            if ($this->hasReportValuesForVersionMetric($vm)) {
                $name = $vm->getMetric()?->getName() ?? (string) $metricId;
                throw new \RuntimeException(
                    'Нельзя убрать метрику «' . $name . '» из версии: по ней уже есть значения в отчётах.',
                );
            }
            $this->removeVersionMetric($vm);
        }

        try {
            $this->em->flush();
        } catch (ForeignKeyConstraintViolationException $e) {
            throw new \RuntimeException(
                'Нельзя изменить состав: по одной или нескольким метрикам уже есть значения в отчётах.',
                0,
                $e,
            );
        }

        foreach ($selectedMetrics as $metricIdStr => $data) {
            $metricId = (int) $metricIdStr;
            if (!isset($vmByMetricId[$metricId])) {
                continue;
            }
            $parentMetricId = isset($data['parent_metric_id']) ? (int) $data['parent_metric_id'] : 0;
            if ($parentMetricId === 0 || $parentMetricId === $metricId) {
                continue;
            }
            if (!isset($vmByMetricId[$parentMetricId])) {
                continue;
            }
            $vmByMetricId[$metricId]->setParent($vmByMetricId[$parentMetricId]);
        }

        $this->em->flush();
    }

    public function hasReportsForVersion(AnalyticsBoardVersion $version): bool
    {
        return $this->em->getRepository(\App\Entity\Analytics\AnalyticsReport::class)->count([
            'boardVersion' => $version,
        ]) > 0;
    }

    public function removeVersion(AnalyticsBoardVersion $version): void
    {
        if ($version->getBoard()?->getActiveVersion() === $version) {
            throw new \RuntimeException('Нельзя удалить активную версию доски.');
        }

        if ($this->hasReportsForVersion($version)) {
            throw new \RuntimeException('Нельзя удалить версию, по которой уже есть отчёты.');
        }

        $this->em->remove($version);
    }

    public function flush(): void
    {
        $this->em->flush();
    }
}
