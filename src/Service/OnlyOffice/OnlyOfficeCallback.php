<?php

declare(strict_types=1);

namespace App\Service\OnlyOffice;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OnlyOfficeCallback
{
    public function __construct(
        private readonly OnlyOfficeJwt $jwt,
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%onlyoffice_document_server_url%')]
        private readonly string $publicUrl,
        #[Autowire('%onlyoffice_internal_url%')]
        private readonly string $internalUrl,
    ) {
    }

    public function data(Request $request): ?array
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return null;
        }
        $token = $data['token'] ?? null;
        if (!is_string($token)) {
            $header = (string) $request->headers->get('Authorization', '');
            $token = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
        }
        $payload = $this->jwt->decode($token);

        return $payload === null ? null : (is_array($payload['payload'] ?? null) ? $payload['payload'] : $payload);
    }

    public function downloadUrl(string $url): ?string
    {
        $source = parse_url($url);
        if (!is_array($source) || isset($source['user']) || isset($source['pass'])
            || isset($source['fragment']) || !in_array($source['scheme'] ?? '', ['http', 'https'], true)) {
            return null;
        }
        foreach ([$this->publicUrl, $this->internalUrl] as $base) {
            $allowed = parse_url($base);
            if (!is_array($allowed) || !isset($allowed['host'], $source['host'])) {
                continue;
            }
            $port = static fn (array $parts): int => $parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80);
            if (strcasecmp($source['host'], $allowed['host']) !== 0
                || ($source['scheme'] ?? '') !== ($allowed['scheme'] ?? '') || $port($source) !== $port($allowed)) {
                continue;
            }
            $prefix = rtrim($allowed['path'] ?? '', '/');
            $path = $source['path'] ?? '';
            $decoded = rawurldecode($path);
            if (!str_starts_with($path, $prefix . '/cache/files/')
                || preg_match('~[\\\\\x00-\x20]|(?:^|/)\.{1,2}(?:/|$)~', $decoded)) {
                return null;
            }

            return rtrim($this->internalUrl, '/') . substr($path, strlen($prefix))
                . (isset($source['query']) ? '?' . $source['query'] : '');
        }

        return null;
    }

    public function download(string $url): ?string
    {
        $url = $this->downloadUrl($url);
        if ($url === null) {
            return null;
        }
        $response = $this->httpClient->request('GET', $url, [
            'max_redirects' => 0, 'timeout' => 10, 'max_duration' => 15, 'buffer' => false,
        ]);
        try {
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $content = '';
            foreach ($this->httpClient->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    return null;
                }
                $content .= $chunk->getContent();
                if (strlen($content) > 10 * 1024 * 1024) {
                    return null;
                }
            }
            DocxArchiveGuard::validateContent($content);

            return $content;
        } finally {
            $response->cancel();
        }
    }
}
