<?php

declare(strict_types=1);

namespace App\Service\ApiExternal;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ответ для файлов, проксируемых с внешнего портала (обращения граждан, заявки
 * на договор, резюме кандидатов).
 *
 * Эти файлы загружают посторонние люди на публичных формах. Раньше Content-Type
 * и Content-Disposition внешнего сервиса отдавались как есть (BE-13): text/html
 * или image/svg+xml, открытые inline, исполняли скрипт на origin портала в сессии
 * сотрудника. Теперь тип определяется локально по белому списку и сверяется с
 * магическими байтами содержимого, диспозиция ставится своим кодом, nosniff —
 * всегда.
 *
 * Inline остаётся только для PDF и растровых картинок: в шаблонах есть кнопка
 * «Просмотр» для PDF и модалка с картинкой, принудительный attachment сломал бы
 * их. SVG и text/* в белый список не входят намеренно.
 */
final class ProxiedFileResponseFactory
{
    /** @var array<string, string> mime → расширение для имени файла */
    private const ALLOWLIST = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/bmp' => 'bmp',
    ];

    /**
     * Типы, которые inline не открываем (октет-стрим + attachment), но имя файла
     * даём с нормальным расширением: резюме и заявки часто в Word/Excel, и
     * «resume-12.bin» человек не откроет. На безопасность имя не влияет —
     * тип ответа и диспозиция всё равно свои.
     *
     * @var array<string, string> mime → расширение
     */
    private const ATTACHMENT_EXTENSIONS = [
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
        'application/rtf' => 'rtf',
        'text/plain' => 'txt',
        'application/zip' => 'zip',
    ];

    public function create(string $content, string $upstreamContentType, bool $download, string $baseName): Response
    {
        $mime = $this->resolveMime($content, $upstreamContentType);
        $inlineAllowed = $mime !== null;
        $contentType = $inlineAllowed ? $mime : 'application/octet-stream';
        $filename = $baseName . '.' . ($inlineAllowed
            ? self::ALLOWLIST[$mime]
            : (self::ATTACHMENT_EXTENSIONS[$this->declaredMime($upstreamContentType)] ?? 'bin'));

        $response = new Response($content, Response::HTTP_OK, [
            'Content-Type' => $contentType,
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            $inlineAllowed && !$download ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
            $filename,
        ));

        return $response;
    }

    /**
     * Тип из белого списка, подтверждённый содержимым; null — отдавать как
     * octet-stream. Заголовок апстрима — только подсказка: сам по себе он
     * обходится подменой на стороне внешнего сервиса.
     */
    private function declaredMime(string $upstreamContentType): string
    {
        $declared = strtolower(trim(explode(';', $upstreamContentType)[0]));

        return $declared === 'image/jpg' ? 'image/jpeg' : $declared;
    }

    private function resolveMime(string $content, string $upstreamContentType): ?string
    {
        $declared = $this->declaredMime($upstreamContentType);
        if (!array_key_exists($declared, self::ALLOWLIST)) {
            return null;
        }

        return $this->matchesMagic($content, $declared) ? $declared : null;
    }

    private function matchesMagic(string $content, string $mime): bool
    {
        return match ($mime) {
            'application/pdf' => str_starts_with($content, '%PDF-'),
            'image/jpeg' => str_starts_with($content, "\xFF\xD8\xFF"),
            'image/png' => str_starts_with($content, "\x89PNG\r\n\x1A\n"),
            'image/gif' => str_starts_with($content, 'GIF87a') || str_starts_with($content, 'GIF89a'),
            'image/webp' => str_starts_with($content, 'RIFF') && substr($content, 8, 4) === 'WEBP',
            'image/bmp' => str_starts_with($content, 'BM'),
            default => false,
        };
    }
}
