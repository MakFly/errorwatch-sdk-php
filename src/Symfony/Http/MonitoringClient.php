<?php

namespace ErrorWatch\Symfony\Http;

use ErrorWatch\Sdk\Transport\RequestBudget;
use ErrorWatch\Sdk\Transport\TransportMetrics;
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
    private RequestBudget $budget;
    private TransportMetrics $metrics;

    /** @var list<ResponseInterface> */
    private array $pendingResponses = [];
    private bool $shutdownRegistered = false;

    public function __construct(
        ?string $endpoint,
        ?string $apiKey,
        ?HttpClientInterface $client = null,
        ?ErrorWatchLogger $logger = null,
        ?RequestBudget $budget = null,
        ?TransportMetrics $metrics = null,
    ) {
        $this->endpoint = $endpoint ?? '';
        $this->apiKey = $apiKey ?? '';
        $this->client = $client ?? \Symfony\Component\HttpClient\HttpClient::create();
        $this->logger = $logger;
        $this->configured = $this->validateConfiguration();
        $this->budget = $budget ?? new RequestBudget(50);
        $this->metrics = $metrics ?? new TransportMetrics();
    }

    public function getMetrics(): TransportMetrics
    {
        return $this->metrics;
    }

    public function getBudget(): RequestBudget
    {
        return $this->budget;
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
        $this->dispatchAsync($this->endpoint.'/api/v1/event', $payload);
    }

    private function dispatchAsync(string $url, array $payload): void
    {
        if (!$this->configured) {
            return;
        }
        if (!$this->budget->withinBudget()) {
            $this->metrics->recordDrop('budget_exceeded');
            return;
        }

        try {
            // Symfony HttpClient is lazy: request() returns immediately
            // without issuing the network call. We DO NOT call
            // getStatusCode() / getContent() / toArray() here — that
            // would force a sync wait. The response is parked in
            // $pendingResponses and drained at kernel.terminate via
            // flushAsync(), which uses HttpClient::stream() to wait
            // for all responses concurrently.
            $response = $this->client->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-API-Key' => $this->apiKey,
                ],
                'body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'timeout' => 2,
            ]);
            $this->pendingResponses[] = $response;
            $this->metrics->recordAsync();
            $this->registerShutdownDrainOnce();
        } catch (\Throwable $e) {
            $this->metrics->recordDrop('async_setup_failed');
        }
    }

    public function sendTransactionAsync(array $payload): void
    {
        $this->dispatchAsync($this->endpoint.'/api/v1/performance/transaction', $payload);
    }

    public function sendLogAsync(array $payload): void
    {
        $this->dispatchAsync($this->endpoint.'/api/v1/logs', $payload);
    }

    public function flushAsync(): void
    {
        if (empty($this->pendingResponses) || !$this->configured) {
            return;
        }
        try {
            // stream() multiplexes concurrent responses. Iterating
            // consumes them; we use a soft timeout so a hung server
            // never blocks shutdown beyond the SDK's own timeout.
            foreach ($this->client->stream($this->pendingResponses, 2.0) as $chunk) {
                if ($chunk->isLast() || $chunk->isTimeout()) {
                    continue;
                }
            }
        } catch (\Throwable) {
            // never let drain crash the host process
        } finally {
            $this->pendingResponses = [];
        }
    }

    private function registerShutdownDrainOnce(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function(function (): void {
            $this->flushAsync();
        });
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

    public function sendCronCheckin(array $payload): void
    {
        if (!$this->configured) {
            return;
        }

        try {
            $response = $this->client->request('POST', $this->endpoint.'/api/v1/cron/checkin', [
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
}
