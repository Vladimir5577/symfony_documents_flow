<?php

declare(strict_types=1);

namespace App\Service\ApiExternal\CitizenAppeal;

use App\DTO\CitizenAppeal\CitizenAppealDto;
use App\DTO\CitizenAppeal\CitizenAppealListDto;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CitizenAppealApiService
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
    public function getList(array $filters = []): CitizenAppealListDto
    {
        $response = $this->httpClient->request('GET', $this->apiUrl . '/api/citizen-appeals', [
            'headers' => ['X-API-Key' => $this->apiKey],
            'query'   => array_filter($filters, static fn($v) => $v !== null && $v !== ''),
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Сервис обращений недоступен (код ' . $response->getStatusCode() . ')');
        }

        return CitizenAppealListDto::fromArray($response->toArray());
    }

    /**
     * @throws \RuntimeException
     */
    public function getOne(int $id): CitizenAppealDto
    {
        $response = $this->httpClient->request('GET', $this->apiUrl . '/api/citizen-appeals/' . $id, [
            'headers' => ['X-API-Key' => $this->apiKey],
        ]);

        if ($response->getStatusCode() === 404) {
            throw new \RuntimeException('Обращение не найдено');
        }

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Сервис обращений недоступен (код ' . $response->getStatusCode() . ')');
        }

        $data = $response->toArray()['data'];

        foreach ($data['files'] ?? [] as &$file) {
            $file['url'] = $this->apiUrl . $file['url'];
        }

        return CitizenAppealDto::fromArray($data);
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

        $response = $this->httpClient->request('PATCH', $this->apiUrl . '/api/citizen-appeals/' . $id, [
            'headers' => [
                'X-API-Key'    => $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $body,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode === 404) {
            throw new \RuntimeException('Обращение не найдено');
        }

        if ($statusCode === 422) {
            $data = $response->toArray(false);
            throw new \RuntimeException($data['error']['message'] ?? 'Ошибка валидации');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException('Не удалось сохранить изменения (код ' . $statusCode . ')');
        }
    }
}
