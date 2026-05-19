<?php

declare(strict_types=1);

namespace App\Service\AI;

use App\Exception\AI\RateLimitException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AnthropicClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiUrl,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $maxTokens,
        private readonly int $timeout,
        private readonly string $systemPrompt = '',
    ) {
    }

    /**
     * @param array<int, array{role: string, content: string|array<int, mixed>}> $messages
     * @throws \RuntimeException
     */
    public function sendMessage(array $messages): AnthropicResponse
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('ANTHROPIC_API_KEY не задан в .env');
        }

        $payload = [
            'model'      => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages'   => $messages,
        ];
        if ($this->systemPrompt !== '') {
            $payload['system'] = $this->systemPrompt;
        }

        try {
            $response = $this->httpClient->request('POST', $this->apiUrl . '/v1/messages', [
                'headers' => [
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ],
                'json'    => $payload,
                'timeout' => $this->timeout,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 429) {
                // Прокси сообщает, через сколько секунд можно повторять.
                // Если заголовок отсутствует — берём дефолт 30 сек как разумный минимум.
                $headers = $response->getHeaders(false);
                $retryAfter = (int) ($headers['retry-after'][0] ?? 30);
                if ($retryAfter < 1) {
                    $retryAfter = 30;
                }
                throw new RateLimitException(
                    'Превышен лимит запросов к AI. Попробуйте через ' . $retryAfter . ' сек.',
                    $retryAfter
                );
            }

            if ($statusCode !== 200) {
                $body = $response->getContent(false);
                throw new \RuntimeException('AI API ответил кодом ' . $statusCode . ': ' . $body);
            }

            $data = $response->toArray();
        } catch (RateLimitException $e) {
            throw $e;
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException('Ошибка обращения к AI API: ' . $e->getMessage(), 0, $e);
        }

        $reply = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text' && isset($block['text'])) {
                $reply .= $block['text'];
            }
        }

        if ($reply === '') {
            throw new \RuntimeException('AI API вернул пустой ответ');
        }

        return new AnthropicResponse(
            text:      $reply,
            tokensIn:  isset($data['usage']['input_tokens'])  ? (int) $data['usage']['input_tokens']  : null,
            tokensOut: isset($data['usage']['output_tokens']) ? (int) $data['usage']['output_tokens'] : null,
            model:     (string) ($data['model'] ?? $this->model),
        );
    }
}
