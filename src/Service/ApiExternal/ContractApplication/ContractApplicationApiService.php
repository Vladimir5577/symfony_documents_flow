<?php

declare(strict_types=1);

namespace App\Service\ApiExternal\ContractApplication;

use App\DTO\ContractApplication\ContractApplicationDto;
use App\DTO\ContractApplication\ContractApplicationListDto;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ContractApplicationApiService
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
    public function getList(array $filters = []): ContractApplicationListDto
    {
        $response = $this->httpClient->request('GET', $this->apiUrl . '/api/contract-applications', [
            'headers' => ['X-API-Key' => $this->apiKey],
            'query'   => array_filter($filters, static fn($v) => $v !== null && $v !== ''),
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Сервис заявок недоступен (код ' . $response->getStatusCode() . ')');
        }

        return ContractApplicationListDto::fromArray($response->toArray());
    }

    /**
     * @throws \RuntimeException
     */
    public function getOne(int $id): ContractApplicationDto
    {
        $response = $this->httpClient->request('GET', $this->apiUrl . '/api/contract-applications/' . $id, [
            'headers' => ['X-API-Key' => $this->apiKey],
        ]);

        if ($response->getStatusCode() === 404) {
            throw new \RuntimeException('Заявка не найдена');
        }

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Сервис заявок недоступен (код ' . $response->getStatusCode() . ')');
        }

        return ContractApplicationDto::fromArray($response->toArray()['data']);
    }

    /**
     * @return array{content: string, contentType: string, disposition: string|null}
     * @throws \RuntimeException
     */
    public function getFileContent(int $fileId, bool $download = false): array
    {
        $response = $this->httpClient->request('GET', $this->apiUrl . '/api/contract-applications/files/' . $fileId, [
            'headers' => ['X-API-Key' => $this->apiKey],
            'query'   => $download ? ['download' => '1'] : [],
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode === 404) {
            throw new \RuntimeException('Файл не найден', 404);
        }

        if ($statusCode !== 200) {
            throw new \RuntimeException('Ошибка получения файла (код ' . $statusCode . ')');
        }

        $headers = $response->getHeaders();

        return [
            'content'     => $response->getContent(),
            'contentType' => $headers['content-type'][0] ?? 'application/octet-stream',
            'disposition' => $headers['content-disposition'][0] ?? null,
        ];
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

        $response = $this->httpClient->request('PATCH', $this->apiUrl . '/api/contract-applications/' . $id, [
            'headers' => [
                'X-API-Key'    => $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $body,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode === 404) {
            throw new \RuntimeException('Заявка не найдена');
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
