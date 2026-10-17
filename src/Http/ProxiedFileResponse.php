<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * Ответ с файлом, полученным от внешнего сервиса (обращения граждан, заявки, резюме).
 *
 * Содержимое и Content-Type задаёт посторонний человек, а отдаём мы его с домена
 * портала, где лежат токены сотрудников. Поэтому встроенно показываем только
 * картинки и PDF, остальное — скачивание с нейтральным типом и nosniff.
 */
final class ProxiedFileResponse
{
    private const INLINE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf'];

    /**
     * @param array{content: string, contentType: string, disposition: ?string} $file
     */
    public static function create(array $file): Response
    {
        $contentType = strtolower(trim(explode(';', $file['contentType'], 2)[0]));
        $allowInline = \in_array($contentType, self::INLINE_TYPES, true);
        $disposition = $file['disposition'] ?? 'inline';
        if (!$allowInline) {
            $disposition = 'attachment' . (strstr($disposition, ';') ?: '');
        }

        return new Response($file['content'], Response::HTTP_OK, [
            'Content-Type' => $allowInline ? $contentType : 'application/octet-stream',
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
