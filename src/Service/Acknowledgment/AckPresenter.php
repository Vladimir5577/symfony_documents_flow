<?php

declare(strict_types=1);

namespace App\Service\Acknowledgment;

use App\Entity\Acknowledgment\AckCategory;
use App\Entity\Acknowledgment\AckDocument;
use App\Entity\Acknowledgment\AckDocumentFile;
use App\Entity\Acknowledgment\AckDocumentUser;
use App\Entity\User\User;

/**
 * Форма ответов модуля. Вынесена из контроллеров, потому что один и тот же
 * документ отдают и сотруднику, и делопроизводству, а разошедшиеся формы одного
 * объекта на фронте разбирать дороже, чем держать их здесь вместе.
 */
final class AckPresenter
{
    private const DATE_TIME_FORMAT = 'Y-m-d\TH:i:s';

    /**
     * @return array<string, mixed>
     */
    public function presentCategory(AckCategory $category): array
    {
        return [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'sort' => $category->getSort(),
        ];
    }

    /**
     * Документ глазами сотрудника: сам документ плюс его собственная отметка.
     *
     * @return array<string, mixed>
     */
    public function presentDocument(AckDocument $document, ?AckDocumentUser $own, User $viewer): array
    {
        $category = $document->getCategory();

        return [
            'id' => $document->getId(),
            'title' => $document->getTitle(),
            'description' => $document->getDescription(),
            'category' => $category !== null ? $this->presentCategory($category) : null,
            'audience' => $document->getAudience()->value,
            'deadline' => $document->getDeadline()?->format('Y-m-d'),
            'isOverdue' => $document->isOverdueFor($viewer),
            'publishedAt' => $document->getPublishedAt()?->format(self::DATE_TIME_FORMAT),
            'archivedAt' => $document->getArchivedAt()?->format(self::DATE_TIME_FORMAT),
            'files' => array_map(
                fn (AckDocumentFile $file): array => $this->presentFile($file),
                $document->getFiles()->toArray(),
            ),
            'myStatus' => $own?->getStatus()?->value,
            'myStatusLabel' => $own?->getStatus()?->getLabel(),
            'myComment' => $own?->getComment(),
            'myActedAt' => $own?->getActedAt()?->format(self::DATE_TIME_FORMAT),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentFile(AckDocumentFile $file): array
    {
        return [
            'id' => $file->getId(),
            'name' => $file->getOriginalName(),
            'createdAt' => $file->getCreatedAt()->format(self::DATE_TIME_FORMAT),
        ];
    }

    /**
     * Строка реестра делопроизводства: документ и охват по нему.
     *
     * @param array{acknowledged: int, disagreed: int, later: int, rows: int} $counts
     *
     * @return array<string, mixed>
     */
    public function presentRegistryRow(AckDocument $document, array $counts, int $audienceTotal): array
    {
        $category = $document->getCategory();
        $done = $counts['acknowledged'] + $counts['disagreed'];

        return [
            'id' => $document->getId(),
            'title' => $document->getTitle(),
            'category' => $category !== null ? $this->presentCategory($category) : null,
            'audience' => $document->getAudience()->value,
            'deadline' => $document->getDeadline()?->format('Y-m-d'),
            'isDraft' => !$document->isPublished(),
            'publishedAt' => $document->getPublishedAt()?->format(self::DATE_TIME_FORMAT),
            'archivedAt' => $document->getArchivedAt()?->format(self::DATE_TIME_FORMAT),
            'filesCount' => $document->getFiles()->count(),
            'stats' => [
                'total' => $audienceTotal,
                'done' => $done,
                'acknowledged' => $counts['acknowledged'],
                'disagreed' => $counts['disagreed'],
                'later' => $counts['later'],
                'pending' => max(0, $audienceTotal - $done),
            ],
        ];
    }

    /**
     * @param array{userId: int, lastname: string, firstname: string, patronymic: ?string,
     *              status: mixed, comment: ?string, actedAt: ?\DateTimeImmutable} $row
     *
     * @return array<string, mixed>
     */
    public function presentReportRow(array $row): array
    {
        $status = $row['status'];
        $name = trim($row['lastname'] . ' ' . $row['firstname'] . ' ' . ($row['patronymic'] ?? ''));

        return [
            'userId' => (int) $row['userId'],
            'name' => $name,
            'status' => $status?->value,
            'statusLabel' => $status?->getLabel(),
            'comment' => $row['comment'],
            'actedAt' => $row['actedAt']?->format(self::DATE_TIME_FORMAT),
        ];
    }
}
