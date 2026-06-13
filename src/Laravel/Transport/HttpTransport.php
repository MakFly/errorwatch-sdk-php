<?php
declare(strict_types=1);

namespace ErrorWatch\Laravel\Transport;

use ErrorWatch\Sdk\Transport\AsyncTransportInterface;
use ErrorWatch\Sdk\Transport\RequestBudget;
use ErrorWatch\Sdk\Transport\TransportMetrics;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Promise\PromiseInterface;

class HttpTransport implements AsyncTransportInterface
{
    /** Hard cap on connect time on the request hot path. */
    private const HOT_PATH_CONNECT_TIMEOUT_S = 0.3;

    /** Flush the batch buffer early once it reaches this many items. */
    private const MAX_BATCH_ITEMS = 100;

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
    private RequestBudget    $budget;
    private TransportMetrics $metrics;

    /**
     * Batch mode: accumulate every item (events / logs / transactions) in
     * memory and ship them in ONE POST to /api/v1/batch at flush time, instead
     * of one HTTP request per item. Reliable in FrankenPHP classic mode and
     * needs no app-side queue/worker.
     */
    private bool  $batchMode   = false;
    /** @var array<int, array{type: string, payload: array}> */
    private array $batchBuffer = [];
    /** Ingestion protocol: "envelope" (default) or "flare" (POST /api/v1/errors). */
    private string $protocol   = 'envelope';

    public function __construct(
        string $endpoint,
        string $apiKey,
        int    $timeout           = 5,
        int    $failureThreshold  = 3,
        int    $cooldownSeconds   = 30,
        int    $maxRetries        = 2,
        int    $requestBudgetMs   = 50,
        ?RequestBudget $budget    = null,
        ?TransportMetrics $metrics = null,
        string $protocol          = 'envelope',
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->apiKey   = $apiKey;
        $this->timeout  = $timeout;
        $this->protocol = $protocol;

        $this->client = new Client([
            'timeout'         => $timeout,
            'connect_timeout' => self::HOT_PATH_CONNECT_TIMEOUT_S,
            'http_errors'     => false, // Don't throw on HTTP errors
        ]);

        $this->circuitBreaker = new CircuitBreaker($failureThreshold, $cooldownSeconds);
        $this->retryHandler   = new RetryHandler($maxRetries);
        $this->budget         = $budget  ?? new RequestBudget($requestBudgetMs);
        $this->metrics        = $metrics ?? new TransportMetrics();

        register_shutdown_function([$this, 'flushAsync']);
    }

    /**
     * True while a server-advertised rate-limit window is still active.
     * Honoured by BOTH the sync and async send paths so the SDK backs off
     * locally instead of hammering the ingest endpoint with doomed requests.
     */
    private function isRateLimited(): bool
    {
        return $this->rateLimitRemaining <= 0
            && $this->rateLimitResetAt !== null
            && time() < $this->rateLimitResetAt;
    }

    /**
     * Update local rate-limit state from a response's headers
     * (`X-RateLimit-Remaining` / `X-RateLimit-Reset` / `Retry-After`).
     * On a 429, force a back-off window even if the server only sent
     * `Retry-After` (or nothing). Returns the Retry-After seconds if present.
     */
    private function noteRateLimit($response, int $statusCode): ?int
    {
        $retryAfter = null;

        if (method_exists($response, 'getHeaderLine')) {
            $remaining = $response->getHeaderLine('X-RateLimit-Remaining');
            $reset     = $response->getHeaderLine('X-RateLimit-Reset');
            $ra        = $response->getHeaderLine('Retry-After');

            if ($remaining !== '') {
                $this->rateLimitRemaining = (int) $remaining;
            }
            if ($reset !== '') {
                $this->rateLimitResetAt = (int) $reset;
            }
            if ($ra !== '') {
                $retryAfter = (int) $ra;
            }
        }

        if ($statusCode === 429) {
            $this->rateLimitRemaining = 0;
            if ($this->rateLimitResetAt === null || $this->rateLimitResetAt <= time()) {
                $this->rateLimitResetAt = time() + max(1, $retryAfter ?? 1);
            }
        }

        return $retryAfter;
    }

    /**
     * Turn batch mode on/off. When on, every send becomes an in-memory
     * accumulation flushed as a single POST to /api/v1/batch.
     */
    public function enableBatchMode(bool $on = true): void
    {
        $this->batchMode = $on;
    }

    /**
     * Buffer one item for the next batch flush. Flushes early if the buffer
     * grows past MAX_BATCH_ITEMS so memory stays bounded on a long request.
     */
    private function accumulate(string $type, array $payload): void
    {
        $this->batchBuffer[] = ['type' => $type, 'payload' => $payload];
        if (count($this->batchBuffer) >= self::MAX_BATCH_ITEMS) {
            $this->flushBatch();
        }
    }

    /**
     * Surface a client-side rejection (4xx, esp. 413 Payload Too Large / 422
     * Unprocessable Entity) instead of swallowing it. These never succeed on
     * retry, so we do NOT retry — we record the failure on the metrics so a
     * debug command can see it, and emit a concise one-line diagnostic.
     */
    private function noteClientRejection(string $endpoint, int $statusCode, string $itemType, int $rejectedCount): void
    {
        $this->metrics->recordSend(0.0, false, "http_{$statusCode}");
        error_log(sprintf(
            '[ErrorWatch] Rejected by API: HTTP %d %s (%d %s item%s discarded, not retried).',
            $statusCode,
            $endpoint,
            $rejectedCount,
            $itemType,
            $rejectedCount === 1 ? '' : 's',
        ));
    }

    /**
     * Ship the buffered items as a single POST to /api/v1/batch.
     * Honours the circuit breaker + rate-limit window; never throws.
     */
    public function flushBatch(): void
    {
        if (empty($this->batchBuffer)) {
            return;
        }
        // Drop the buffer if we're not allowed to send — better to lose
        // telemetry than to block the host or hammer a limited endpoint.
        if (!$this->circuitBreaker->allowRequest()) {
            $this->metrics->recordDrop('circuit_open');
            $this->batchBuffer = [];
            return;
        }
        if ($this->isRateLimited()) {
            $this->metrics->recordDrop('rate_limited');
            $this->batchBuffer = [];
            return;
        }

        $items = $this->batchBuffer;
        $this->batchBuffer = [];
        $startedAt = microtime(true);

        try {
            $response = $this->client->post($this->getBatchUrl(), [
                'headers' => $this->getHeaders(),
                'json'    => ['items' => $items],
            ]);

            $this->budget->consume((microtime(true) - $startedAt) * 1000.0);
            $statusCode = $response->getStatusCode();
            $this->noteRateLimit($response, $statusCode);

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->circuitBreaker->recordSuccess();
                $this->metrics->recordSend((microtime(true) - $startedAt) * 1000.0, true);
            } elseif ($statusCode >= 500) {
                $this->circuitBreaker->recordFailure();
                $this->metrics->recordSend((microtime(true) - $startedAt) * 1000.0, false, "http_{$statusCode}");
            } elseif ($statusCode >= 400 && $statusCode !== 429) {
                // 4xx (incl 413/422) are not retried — items are already
                // cleared — but surface the rejection instead of swallowing it.
                $this->noteClientRejection($this->getBatchUrl(), $statusCode, 'batch', count($items));
            }
            // 429 opens the rate-limit window above; items are not retried.
        } catch (GuzzleException $e) {
            $this->budget->consume((microtime(true) - $startedAt) * 1000.0);
            $this->circuitBreaker->recordFailure();
            $this->metrics->recordSend((microtime(true) - $startedAt) * 1000.0, false, $e->getMessage());
            error_log('[ErrorWatch] Batch send failed: ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->circuitBreaker->recordFailure();
            error_log('[ErrorWatch] Batch send failed: ' . $e->getMessage());
        }
    }

    /**
     * Send an event to the ErrorWatch API.
     * Applies circuit breaker, rate-limit guard, and retry logic.
     */
    public function send(array $payload): bool
    {
        // Flare error events go straight to /api/v1/errors — the mixed /batch
        // endpoint only understands envelope-shaped events.
        if ($this->batchMode && $this->protocol !== 'flare') {
            $this->accumulate('event', $payload);
            return true;
        }
        if (!$this->budget->withinBudget()) {
            $this->metrics->recordDrop('budget_exceeded');
            return false;
        }

        if (!$this->circuitBreaker->allowRequest()) {
            $this->metrics->recordDrop('circuit_open');
            error_log('[ErrorWatch] Circuit breaker OPEN — skipping event send.');
            return false;
        }

        if ($this->isRateLimited()) {
            $this->metrics->recordDrop('rate_limited');
            error_log('[ErrorWatch] Rate limit active — skipping event send.');
            return false;
        }

        $attempt = 0;
        $sendStartedAt = microtime(true);

        do {
            if ($attempt > 0) {
                // Honour the per-request budget: never sleep past it.
                $retryAfter = isset($retryAfterSeconds) ? $retryAfterSeconds : null;
                $delayMs    = $this->retryHandler->getDelay($attempt - 1, $retryAfter);
                $remaining  = (int) $this->budget->remaining();
                if ($remaining <= 0) {
                    $this->metrics->recordDrop('budget_exceeded');
                    return false;
                }
                usleep(min($delayMs, $remaining) * 1000);
            }

            $retryAfterSeconds = null;
            $statusCode        = 0;

            try {
                $response   = $this->client->post($this->getEventUrl(), [
                    'headers' => $this->getHeaders(),
                    'json'    => $payload,
                ]);

                $statusCode = $response->getStatusCode();

                // Update rate-limit state (X-RateLimit-* / Retry-After / 429 back-off).
                $retryAfterSeconds = $this->noteRateLimit($response, $statusCode);

                $this->budget->consume((microtime(true) - $sendStartedAt) * 1000.0);

                if ($statusCode >= 200 && $statusCode < 300) {
                    $this->circuitBreaker->recordSuccess();
                    $this->metrics->recordSend((microtime(true) - $sendStartedAt) * 1000.0, true);
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

                // Non-retryable error (4xx, incl 413/422) — surface it on the
                // metrics + a concise diagnostic instead of swallowing.
                $this->noteClientRejection($this->getEventUrl(), $statusCode, 'event', 1);
                return false;

            } catch (GuzzleException $e) {
                // Connection-level failure counts against the circuit breaker
                $this->circuitBreaker->recordFailure();
                $this->budget->consume((microtime(true) - $sendStartedAt) * 1000.0);
                $this->metrics->recordSend((microtime(true) - $sendStartedAt) * 1000.0, false, $e->getMessage());
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
        if ($this->batchMode && $this->protocol !== 'flare') {
            $this->accumulate('event', $payload);
            return;
        }
        $this->dispatchAsync($this->getEventUrl(), $payload);
    }

    /**
     * Fire-and-forget transaction send (APM).
     */
    public function sendTransactionAsync(array $transaction, ?string $env = null): void
    {
        if ($this->batchMode) {
            $this->accumulate('transaction', ['transaction' => $transaction, 'env' => $env]);
            return;
        }
        $this->dispatchAsync($this->getTransactionUrl(), [
            'transaction' => $transaction,
            'env'         => $env,
        ]);
    }

    /**
     * Fire-and-forget log send.
     */
    public function sendLogAsync(array $logEntry): void
    {
        if ($this->batchMode) {
            $this->accumulate('log', $logEntry);
            return;
        }
        $this->dispatchAsync($this->getLogsUrl(), $logEntry);
    }

    private function dispatchAsync(string $url, array $payload): void
    {
        if (!$this->budget->withinBudget()) {
            $this->metrics->recordDrop('budget_exceeded');
            return;
        }
        if (!$this->circuitBreaker->allowRequest()) {
            $this->metrics->recordDrop('circuit_open');
            return;
        }
        if ($this->isRateLimited()) {
            $this->metrics->recordDrop('rate_limited');
            return;
        }

        try {
            $request = new Request(
                'POST',
                $url,
                $this->getHeaders(),
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
            );

            $promise = $this->client->sendAsync($request, [
                'timeout'         => $this->timeout,
                'connect_timeout' => self::HOT_PATH_CONNECT_TIMEOUT_S,
            ])->then(
                function ($response): void {
                    if (method_exists($response, 'getStatusCode')) {
                        $status = $response->getStatusCode();
                        // Honour server rate-limit headers on the async path too,
                        // so the next requests back off instead of flooding.
                        $this->noteRateLimit($response, $status);
                        if ($status >= 200 && $status < 300) {
                            $this->circuitBreaker->recordSuccess();
                        } elseif ($status === 429) {
                            $this->metrics->recordDrop('rate_limited');
                        } elseif ($status >= 500) {
                            $this->circuitBreaker->recordFailure();
                            $this->metrics->recordSend(0.0, false, "http_{$status}");
                        }
                    }
                },
                function (\Throwable $e): void {
                    $this->circuitBreaker->recordFailure();
                    $this->metrics->recordSend(0.0, false, $e->getMessage());
                    error_log('[ErrorWatch] Async send failed: ' . $e->getMessage());
                }
            );

            $this->pendingPromises[] = $promise;
            $this->metrics->recordAsync();

        } catch (\Throwable $e) {
            $this->metrics->recordDrop('async_setup_failed');
            error_log('[ErrorWatch] Failed to create async request: ' . $e->getMessage());
        }
    }

    /**
     * Flush all pending async promises AND queued synchronous events.
     * Registered as a shutdown function so PHP-FPM does not discard in-flight requests.
     */
    public function flushAsync(): void
    {
        // Ship any buffered batch items first (batch mode).
        $this->flushBatch();

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
     * Send each event to /api/v1/envelope (Sentry-style rich payload).
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
        if ($this->batchMode) {
            $this->accumulate('log', $logEntry);
            return true;
        }
        if (!$this->budget->withinBudget()) {
            $this->metrics->recordDrop('budget_exceeded');
            return false;
        }
        if (!$this->circuitBreaker->allowRequest()) {
            $this->metrics->recordDrop('circuit_open');
            error_log('[ErrorWatch] Circuit breaker OPEN — skipping log send.');
            return false;
        }

        $startedAt = microtime(true);

        try {
            $response = $this->client->post($this->getLogsUrl(), [
                'headers' => $this->getHeaders(),
                'json'    => $logEntry,
            ]);

            // Consume the budget right after the response, before inspecting
            // the status — a slow non-2xx response must count too.
            $this->budget->consume((microtime(true) - $startedAt) * 1000.0);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->circuitBreaker->recordSuccess();
                return true;
            }

            // 4xx (incl 413 Payload Too Large / 422 Unprocessable Entity) are
            // not retried — surface them instead of swallowing silently.
            if ($statusCode >= 400 && $statusCode < 500) {
                $this->noteClientRejection($this->getLogsUrl(), $statusCode, 'log', 1);
            } else {
                error_log("[ErrorWatch] Failed to send log: HTTP {$statusCode}.");
            }
            return false;

        } catch (GuzzleException $e) {
            $this->budget->consume((microtime(true) - $startedAt) * 1000.0);
            $this->circuitBreaker->recordFailure();
            error_log('[ErrorWatch] Failed to send log: ' . $e->getMessage());
            return false;
        } catch (\Throwable $e) {
            // The SDK must never break the host app — swallow non-Guzzle errors
            // (JSON encoding, etc.) and still account for the elapsed time.
            $this->budget->consume((microtime(true) - $startedAt) * 1000.0);
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
        if ($this->batchMode) {
            $this->accumulate('transaction', ['transaction' => $transaction, 'env' => $env]);
            return true;
        }
        if (!$this->budget->withinBudget()) {
            $this->metrics->recordDrop('budget_exceeded');
            return false;
        }
        if (!$this->circuitBreaker->allowRequest()) {
            $this->metrics->recordDrop('circuit_open');
            error_log('[ErrorWatch] Circuit breaker OPEN — skipping transaction send.');
            return false;
        }

        $payload = [
            'transaction' => $transaction,
            'env'         => $env,
        ];

        $startedAt = microtime(true);

        try {
            $response = $this->client->post($this->getTransactionUrl(), [
                'headers' => $this->getHeaders(),
                'json'    => $payload,
            ]);

            // Consume the budget right after the response, before inspecting
            // the status — a slow non-2xx response must count too.
            $this->budget->consume((microtime(true) - $startedAt) * 1000.0);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->circuitBreaker->recordSuccess();
                return true;
            }

            // 4xx (incl 413/422) are not retried — surface them instead of
            // swallowing silently.
            if ($statusCode >= 400 && $statusCode < 500) {
                $this->noteClientRejection($this->getTransactionUrl(), $statusCode, 'transaction', 1);
            } else {
                error_log("[ErrorWatch] Failed to send transaction: HTTP {$statusCode}.");
            }
            return false;

        } catch (GuzzleException $e) {
            $this->budget->consume((microtime(true) - $startedAt) * 1000.0);
            $this->circuitBreaker->recordFailure();
            error_log('[ErrorWatch] Failed to send transaction: ' . $e->getMessage());
            return false;
        } catch (\Throwable $e) {
            // The SDK must never break the host app — swallow non-Guzzle errors
            // (JSON encoding, etc.) and still account for the elapsed time.
            $this->budget->consume((microtime(true) - $startedAt) * 1000.0);
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
        $this->batchBuffer        = [];
        $this->rateLimitRemaining = PHP_INT_MAX;
        $this->rateLimitResetAt   = null;
        $this->budget->reset();
    }

    /**
     * Expose runtime metrics for observability / debug commands.
     */
    public function getMetrics(): TransportMetrics
    {
        return $this->metrics;
    }

    /**
     * Expose the per-request budget instance (read-only inspection).
     */
    public function getBudget(): RequestBudget
    {
        return $this->budget;
    }

    /**
     * Get the event endpoint URL.
     */
    protected function getEventUrl(): string
    {
        if ($this->protocol === 'flare') {
            return $this->endpoint . '/api/v1/errors';
        }
        return $this->endpoint . '/api/v1/envelope';
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
     * Get the batch ingest endpoint URL.
     */
    protected function getBatchUrl(): string
    {
        return $this->endpoint . '/api/v1/batch';
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
