<?php

declare(strict_types=1);

namespace App\Service\ApiExternal\Vacancy;

use App\DTO\Vacancy\VacancyDto;
use App\DTO\Vacancy\VacancyListDto;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class VacancyApiService
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
    public function getList(array $filters = []): VacancyListDto
    {
        $response = $this->httpClient->request('GET', $this->apiUrl . '/api/vacancies', [
            'headers' => ['X-API-Key' => $this->apiKey],
            'query'   => array_filter($filters, static fn($v) => $v !== null && $v !== ''),
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Сервис вакансий недоступен (код ' . $response->getStatusCode() . ')');
        }

        return VacancyListDto::fromArray($response->toArray());
    }

    /**
     * @throws \RuntimeException
     */
    public function getOne(int $id): VacancyDto
    {
        $response = $this->httpClient->request('GET', $this->apiUrl . '/api/vacancies/' . $id, [
            'headers' => ['X-API-Key' => $this->apiKey],
        ]);

        if ($response->getStatusCode() === 404) {
            throw new \RuntimeException('Вакансия не найдена');
        }

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Сервис вакансий недоступен (код ' . $response->getStatusCode() . ')');
        }

        return VacancyDto::fromArray($response->toArray()['data']);
    }

    /**
     * @param array<string, mixed> $data
     * @throws \RuntimeException
     */
    public function create(array $data): VacancyDto
    {
        $response = $this->httpClient->request('POST', $this->apiUrl . '/api/vacancies', [
            'headers' => [
                'X-API-Key'    => $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $data,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode === 422) {
            $body = $response->toArray(false);
            $violations = $body['error']['violations'] ?? [];
            $message = $body['error']['message'] ?? 'Ошибка валидации';
            if ($violations) {
                $message .= ': ' . implode('; ', array_map(
                    static fn($field, $msg) => "$field — $msg",
                    array_keys($violations),
                    $violations,
                ));
            }
            throw new \RuntimeException($message);
        }

        if ($statusCode !== 201) {
            throw new \RuntimeException('Не удалось создать вакансию (код ' . $statusCode . ')');
        }

        return VacancyDto::fromArray($response->toArray()['data']);
    }

    /**
     * @param array<string, mixed> $data
     * @throws \RuntimeException
     */
    public function update(int $id, array $data): VacancyDto
    {
        $response = $this->httpClient->request('PATCH', $this->apiUrl . '/api/vacancies/' . $id, [
            'headers' => [
                'X-API-Key'    => $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $data,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode === 404) {
            throw new \RuntimeException('Вакансия не найдена');
        }

        if ($statusCode === 422) {
            $body = $response->toArray(false);
            $violations = $body['error']['violations'] ?? [];
            $message = $body['error']['message'] ?? 'Ошибка валидации';
            if ($violations) {
                $message .= ': ' . implode('; ', array_map(
                    static fn($field, $msg) => "$field — $msg",
                    array_keys($violations),
                    $violations,
                ));
            }
            throw new \RuntimeException($message);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException('Не удалось сохранить изменения (код ' . $statusCode . ')');
        }

        return VacancyDto::fromArray($response->toArray()['data']);
    }

}
