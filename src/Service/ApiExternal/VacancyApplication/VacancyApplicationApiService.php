<?php

declare(strict_types=1);

namespace App\Service\ApiExternal\VacancyApplication;

use App\DTO\VacancyApplication\VacancyApplicationDto;
use App\DTO\VacancyApplication\VacancyApplicationListDto;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class VacancyApplicationApiService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiUrl,
        private readonly string $apiKey,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @throws \RuntimeException
     */
    public function getList(array $filters = []): VacancyApplicationListDto
    {
        $response = $this->httpClient->request('GET', $this->apiUrl . '/api/vacancy-applications', [
            'headers' => ['X-API-Key' => $this->apiKey],
            'query'   => array_filter($filters, static fn($v) => $v !== null && $v !== ''),
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Сервис откликов недоступен (код ' . $response->getStatusCode() . ')');
        }

        return VacancyApplicationListDto::fromArray($response->toArray());
    }

    /**
     * @throws \RuntimeException
     */
    public function getOne(int $id): VacancyApplicationDto
    {
        $response = $this->httpClient->request('GET', $this->apiUrl . '/api/vacancy-applications/' . $id, [
            'headers' => ['X-API-Key' => $this->apiKey],
        ]);

        if ($response->getStatusCode() === 404) {
            throw new \RuntimeException('Отклик не найден');
        }

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Сервис откликов недоступен (код ' . $response->getStatusCode() . ')');
        }

        return VacancyApplicationDto::fromArray($response->toArray()['data']);
    }

    /**
     * @throws \RuntimeException
     */
    public function update(int $id, ?string $status, ?string $adminComment): void
    {
        $body = array_filter([
            'status'       => $status,
            'adminComment' => $adminComment,
        ], static fn($v) => $v !== null);

        $response = $this->httpClient->request('PATCH', $this->apiUrl . '/api/vacancy-applications/' . $id, [
            'headers' => [
                'X-API-Key'    => $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $body,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode === 404) {
            throw new \RuntimeException('Отклик не найден');
        }

        if ($statusCode === 422) {
            $data = $response->toArray(false);
            throw new \RuntimeException($data['error']['message'] ?? 'Ошибка валидации');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException('Не удалось сохранить изменения (код ' . $statusCode . ')');
        }
    }

    /**
     * @return array{content: string, contentType: string, disposition: string|null}
     * @throws \RuntimeException
     */
    public function getResumeContent(int $id, bool $download = false): array
    {
        $response = $this->httpClient->request('GET', $this->apiUrl . '/api/vacancy-applications/' . $id . '/resume', [
            'headers' => ['X-API-Key' => $this->apiKey],
            'query'   => $download ? ['download' => '1'] : [],
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode === 404) {
            throw new \RuntimeException('Резюме не найдено', 404);
        }

        if ($statusCode !== 200) {
            throw new \RuntimeException('Ошибка получения резюме (код ' . $statusCode . ')');
        }

        $headers = $response->getHeaders();

        return [
            'content'     => $response->getContent(),
            'contentType' => $headers['content-type'][0] ?? 'application/octet-stream',
            'disposition' => $headers['content-disposition'][0] ?? null,
        ];
    }
}
