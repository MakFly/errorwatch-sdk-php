<?php

namespace ErrorWatch\Symfony\Http;

use ErrorWatch\Symfony\Service\ErrorWatchLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class MonitoringClient implements MonitoringClientInterface
{
    private string $endpoint;
    private string $apiKey;
    private HttpClientInterface $client;
    private ?ErrorWatchLogger $logger;
    private bool $configured;

    public function __construct(
        ?string $endpoint,
        ?string $apiKey,
        ?HttpClientInterface $client = null,
        ?ErrorWatchLogger $logger = null
    ) {
        $this->endpoint = $endpoint ?? '';
        $this->apiKey = $apiKey ?? '';
        $this->client = $client ?? \Symfony\Component\HttpClient\HttpClient::create();
        $this->logger = $logger;
        $this->configured = $this->validateConfiguration();
    }

    /**
     * Check if the client is properly configured.
     */
    public function isConfigured(): bool
    {
        return $this->configured;
    }

    /**
     * Validate that endpoint and API key are set.
     * Logs warnings if not configured properly.
     */
    private function validateConfiguration(): bool
    {
        if ('' === $this->endpoint) {
            $this->logger?->warning('endpoint is empty. All events will be silently dropped.', [
                'hint' => 'Set ERRORWATCH_ENDPOINT in your .env file',
            ]);

            return false;
        }

        if ('' === $this->apiKey) {
            $this->logger?->warning('api_key is empty. All events will be silently dropped.', [
                'hint' => 'Set ERRORWATCH_API_KEY in your .env file',
            ]);

            return false;
        }

        return true;
    }

    /**
     * Create a mock response for when the client is not configured.
     * This allows graceful degradation without breaking the application.
     */
    private function createMockResponse(): ResponseInterface
    {
        return new class implements ResponseInterface {
            public function getStatusCode(): int
            {
                return 0;
            }

            public function getHeaders(bool $throw = true): array
            {
                return [];
            }

            public function getContent(bool $throw = true): string
            {
                return '';
            }

            /**
             * @return array<mixed>
             */
            public function toArray(bool $throw = true): array
            {
                return [];
            }

            public function cancel(): void
            {
            }

            public function getInfo(?string $type = null): mixed
            {
                return null;
            }
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function sendEvent(array $payload): ResponseInterface
    {
        if (!$this->configured) {
            return $this->createMockResponse();
        }

        return $this->client->request('POST', $this->endpoint.'/api/v1/event', [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-Key' => $this->apiKey,
            ],
            'body' => json_encode($payload),
            'timeout' => 1,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function sendEventAsync(array $payload): void
    {
        if (!$this->configured) {
            return;
        }

        try {
            $response = $this->sendEvent($payload);
            // Force the request to be sent (Symfony HttpClient is lazy)
            $response->getStatusCode();
        } catch (\Throwable $e) {
            // Silently fail to avoid breaking the application
        }
    }

    public function sendTransaction(array $payload): void
    {
        if (!$this->configured) {
            return;
        }

        try {
            $response = $this->client->request('POST', $this->endpoint.'/api/v1/performance/transaction', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-API-Key' => $this->apiKey,
                ],
                'body' => json_encode($payload),
                'timeout' => 2,
            ]);
            $response->getStatusCode();
        } catch (\Throwable) {
        }
    }

    public function sendMetrics(array $payload): void
    {
        if (!$this->configured) {
            return;
        }

        try {
            $response = $this->client->request('POST', $this->endpoint.'/api/v1/performance/metrics', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-API-Key' => $this->apiKey,
                ],
                'body' => json_encode($payload),
                'timeout' => 2,
            ]);
            $response->getStatusCode();
        } catch (\Throwable) {
        }
    }

    public function sendLog(array $payload): void
    {
        if (!$this->configured) {
            return;
        }

        try {
            $response = $this->client->request('POST', $this->endpoint.'/api/v1/logs', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-API-Key' => $this->apiKey,
                ],
                'body' => json_encode($payload),
                'timeout' => 1,
            ]);
            $response->getStatusCode();
        } catch (\Throwable) {
        }
    }
}
