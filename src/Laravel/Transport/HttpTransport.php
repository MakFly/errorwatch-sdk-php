<?php
declare(strict_types=1);

namespace ErrorWatch\Laravel\Transport;

use ErrorWatch\Sdk\Transport\TransportInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Promise\PromiseInterface;

class HttpTransport implements TransportInterface
{
    protected Client         $client;
    protected string         $endpoint;
    protected string         $apiKey;
    protected int            $timeout;
    protected array          $pendingEvents    = [];

    private CircuitBreaker   $circuitBreaker;
    private RetryHandler     $retryHandler;
    private array            $pendingPromises  = [];
    private ?int             $rateLimitResetAt = null;
    private int              $rateLimitRemaining = PHP_INT_MAX;

    public function __construct(
        string $endpoint,
        string $apiKey,
        int    $timeout           = 5,
        int    $failureThreshold  = 5,
        int    $cooldownSeconds   = 60,
        int    $maxRetries        = 2,
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->apiKey   = $apiKey;
        $this->timeout  = $timeout;

        $this->client = new Client([
            'timeout'         => $timeout,
            'connect_timeout' => 3,
            'http_errors'     => false, // Don't throw on HTTP errors
        ]);

        $this->circuitBreaker = new CircuitBreaker($failureThreshold, $cooldownSeconds);
        $this->retryHandler   = new RetryHandler($maxRetries);

        register_shutdown_function([$this, 'flushAsync']);
    }

    /**
     * Send an event to the ErrorWatch API.
     * Applies circuit breaker, rate-limit guard, and retry logic.
     */
    public function send(array $payload): bool
    {
        if (!$this->circuitBreaker->allowRequest()) {
            error_log('[ErrorWatch] Circuit breaker OPEN — skipping event send.');
            return false;
        }

        if ($this->rateLimitRemaining <= 0 && $this->rateLimitResetAt !== null && time() < $this->rateLimitResetAt) {
            error_log('[ErrorWatch] Rate limit active — skipping event send.');
            return false;
        }

        $attempt = 0;

        do {
            if ($attempt > 0) {
                $retryAfter = isset($retryAfterSeconds) ? $retryAfterSeconds : null;
                $delayMs    = $this->retryHandler->getDelay($attempt - 1, $retryAfter);
                usleep($delayMs * 1000);
            }

            $retryAfterSeconds = null;
            $statusCode        = 0;

            try {
                $response   = $this->client->post($this->getEventUrl(), [
                    'headers' => $this->getHeaders(),
                    'json'    => $payload,
                ]);

                $statusCode = $response->getStatusCode();

                // Parse rate-limit headers
                $remaining = $response->getHeaderLine('X-RateLimit-Remaining');
                $reset     = $response->getHeaderLine('X-RateLimit-Reset');
                if ($remaining !== '') {
                    $this->rateLimitRemaining = (int) $remaining;
                }
                if ($reset !== '') {
                    $this->rateLimitResetAt = (int) $reset;
                }

                // Parse Retry-After header for 429
                $retryAfterHeader = $response->getHeaderLine('Retry-After');
                if ($retryAfterHeader !== '') {
                    $retryAfterSeconds = (int) $retryAfterHeader;
                }

                if ($statusCode >= 200 && $statusCode < 300) {
                    $this->circuitBreaker->recordSuccess();
                    return true;
                }

                // 429: respect back-off but do NOT count as circuit breaker failure
                if ($statusCode === 429) {
                    if (!$this->retryHandler->shouldRetry($attempt, $statusCode)) {
                        return false;
                    }
                    $attempt++;
                    continue;
                }

                // 5xx / 503
                if ($this->retryHandler->isRetryableStatus($statusCode)) {
                    $this->circuitBreaker->recordFailure();
                    if (!$this->retryHandler->shouldRetry($attempt, $statusCode)) {
                        error_log("[ErrorWatch] Failed to send event (HTTP {$statusCode}) after {$attempt} attempt(s).");
                        return false;
                    }
                    $attempt++;
                    continue;
                }

                // Non-retryable error (4xx etc.)
                error_log("[ErrorWatch] Failed to send event: HTTP {$statusCode}.");
                return false;

            } catch (GuzzleException $e) {
                // Connection-level failure counts against the circuit breaker
                $this->circuitBreaker->recordFailure();
                error_log('[ErrorWatch] Failed to send event: ' . $e->getMessage());
                return false;
            }

        } while (true);
    }

    /**
     * Send an event asynchronously (non-blocking).
     * The promise is stored and resolved in flushAsync() at shutdown.
     */
    public function sendAsync(array $payload): void
    {
        try {
            $request = new Request(
                'POST',
                $this->getEventUrl(),
                $this->getHeaders(),
                json_encode($payload)
            );

            $promise = $this->client->sendAsync($request)->then(
                null,
                function (\Exception $e): void {
                    error_log('[ErrorWatch] Async send failed: ' . $e->getMessage());
                }
            );

            $this->pendingPromises[] = $promise;

        } catch (\Exception $e) {
            error_log('[ErrorWatch] Failed to create async request: ' . $e->getMessage());
        }
    }

    /**
     * Flush all pending async promises AND queued synchronous events.
     * Registered as a shutdown function so PHP-FPM does not discard in-flight requests.
     */
    public function flushAsync(): void
    {
        foreach ($this->pendingPromises as $promise) {
            try {
                /** @var PromiseInterface $promise */
                $promise->wait(false);
            } catch (\Exception $e) {
                error_log('[ErrorWatch] Failed to resolve async promise: ' . $e->getMessage());
            }
        }

        $this->pendingPromises = [];

        // Also drain any synchronously queued events
        $this->flush();
    }

    /**
     * Send multiple events by posting each to the single-event endpoint.
     *
     * The monitoring server exposes /api/v1/event (single POST only).
     * There is no batch endpoint, so we iterate and send individually.
     * Returns true only if every event was sent successfully.
     */
    public function sendBatch(array $events): bool
    {
        if (empty($events)) {
            return true;
        }

        $allSucceeded = true;

        foreach ($events as $event) {
            if (!$this->send($event)) {
                $allSucceeded = false;
            }
        }

        return $allSucceeded;
    }

    /**
     * Send a log entry to the ErrorWatch logs endpoint.
     * Circuit breaker check is applied; no retry logic.
     */
    public function sendLog(array $logEntry): bool
    {
        if (!$this->circuitBreaker->allowRequest()) {
            error_log('[ErrorWatch] Circuit breaker OPEN — skipping log send.');
            return false;
        }

        try {
            $response = $this->client->post($this->getLogsUrl(), [
                'headers' => $this->getHeaders(),
                'json'    => $logEntry,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->circuitBreaker->recordSuccess();
                return true;
            }

            error_log("[ErrorWatch] Failed to send log: HTTP {$statusCode}.");
            return false;

        } catch (GuzzleException $e) {
            $this->circuitBreaker->recordFailure();
            error_log('[ErrorWatch] Failed to send log: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a transaction (APM) to the ErrorWatch API.
     * Circuit breaker check is applied; no retry logic.
     */
    public function sendTransaction(array $transaction, ?string $env = null): bool
    {
        if (!$this->circuitBreaker->allowRequest()) {
            error_log('[ErrorWatch] Circuit breaker OPEN — skipping transaction send.');
            return false;
        }

        $payload = [
            'transaction' => $transaction,
            'env'         => $env,
        ];

        try {
            $response = $this->client->post($this->getTransactionUrl(), [
                'headers' => $this->getHeaders(),
                'json'    => $payload,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->circuitBreaker->recordSuccess();
                return true;
            }

            error_log("[ErrorWatch] Failed to send transaction: HTTP {$statusCode}.");
            return false;

        } catch (GuzzleException $e) {
            $this->circuitBreaker->recordFailure();
            error_log('[ErrorWatch] Failed to send transaction: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if the transport is properly configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->endpoint) && !empty($this->apiKey);
    }

    /**
     * Expose the circuit breaker instance (e.g. for Octane reset hooks).
     */
    public function getCircuitBreaker(): CircuitBreaker
    {
        return $this->circuitBreaker;
    }

    /**
     * Reset all mutable state between Octane requests.
     * Clears pending promises, queued events, and rate-limit counters.
     */
    public function resetState(): void
    {
        $this->pendingPromises    = [];
        $this->pendingEvents      = [];
        $this->rateLimitRemaining = PHP_INT_MAX;
        $this->rateLimitResetAt   = null;
    }

    /**
     * Get the event endpoint URL.
     */
    protected function getEventUrl(): string
    {
        return $this->endpoint . '/api/v1/event';
    }

    /**
     * Get the logs endpoint URL.
     */
    protected function getLogsUrl(): string
    {
        return $this->endpoint . '/api/v1/logs';
    }

    /**
     * Get the transaction endpoint URL.
     */
    protected function getTransactionUrl(): string
    {
        return $this->endpoint . '/api/v1/performance/transaction';
    }

    /**
     * Get the common headers for all requests.
     */
    protected function getHeaders(): array
    {
        return [
            'X-API-Key'    => $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'User-Agent'   => 'ErrorWatch-Laravel-SDK/0.2.0',
        ];
    }

    /**
     * Flush any pending queued events (synchronous batch).
     */
    public function flush(): bool
    {
        if (empty($this->pendingEvents)) {
            return true;
        }

        $result             = $this->sendBatch($this->pendingEvents);
        $this->pendingEvents = [];

        return $result;
    }

    /**
     * Add an event to the pending batch.
     */
    public function queue(array $payload): void
    {
        $this->pendingEvents[] = $payload;
    }
}
