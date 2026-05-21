<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class HttpTransport implements AsyncTransportInterface
{
    /** Hard cap on connect time when called from the request hot path. */
    private const HOT_PATH_CONNECT_TIMEOUT_S = 0.3;

    private readonly Client          $client;
    private readonly LoggerInterface $logger;
    private readonly string          $eventUrl;
    private readonly RequestBudget   $budget;
    private readonly TransportMetrics $metrics;

    /** @var list<PromiseInterface> */
    private array $pendingPromises = [];
    private bool  $shutdownRegistered = false;

    public function __construct(
        private readonly string  $endpoint,
        private readonly string  $apiKey,
        private readonly int     $timeout = 5,
        ?LoggerInterface         $logger  = null,
        ?Client                  $client  = null,
        ?RequestBudget           $budget  = null,
        ?TransportMetrics        $metrics = null,
    ) {
        $this->logger   = $logger ?? new NullLogger();
        // /api/v1/envelope accepts the rich Sentry-style payload produced
        // by Sdk\Event::toPayload(). Legacy /api/v1/event remains for flat
        // (file/line/stack) payloads emitted by other integrations.
        $this->eventUrl = rtrim($endpoint, '/') . '/api/v1/envelope';
        $this->client   = $client ?? new Client([
            'timeout'         => $timeout,
            'connect_timeout' => self::HOT_PATH_CONNECT_TIMEOUT_S,
            'http_errors'     => false,
        ]);
        $this->budget  = $budget ?? new RequestBudget(50);
        $this->metrics = $metrics ?? new TransportMetrics();
    }

    public function send(array $payload): bool
    {
        if (!$this->budget->withinBudget()) {
            $this->metrics->recordDrop('budget_exceeded');
            return false;
        }

        $startedAt = microtime(true);
        try {
            $response = $this->client->post($this->eventUrl, [
                'headers' => [
                    'X-API-Key'    => $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'json'    => $payload,
                'timeout' => $this->timeout,
            ]);

            $statusCode = $response->getStatusCode();
            $durationMs = (microtime(true) - $startedAt) * 1000.0;
            $this->budget->consume($durationMs);

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->metrics->recordSend($durationMs, true);
                return true;
            }

            $this->metrics->recordSend($durationMs, false, "http_{$statusCode}");
            $this->logger->warning('[ErrorWatch] Unexpected HTTP response', [
                'status'   => $statusCode,
                'event_id' => $payload['event_id'] ?? null,
            ]);

            return false;
        } catch (GuzzleException $e) {
            $durationMs = (microtime(true) - $startedAt) * 1000.0;
            $this->budget->consume($durationMs);
            $this->metrics->recordSend($durationMs, false, $e->getMessage());
            $this->logger->error('[ErrorWatch] HTTP transport error: ' . $e->getMessage(), [
                'event_id' => $payload['event_id'] ?? null,
            ]);

            return false;
        } catch (\Throwable $e) {
            $durationMs = (microtime(true) - $startedAt) * 1000.0;
            $this->budget->consume($durationMs);
            $this->metrics->recordSend($durationMs, false, $e->getMessage());
            // Absolute last resort — the SDK must never propagate exceptions
            $this->logger->error('[ErrorWatch] Unexpected transport error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Fire-and-forget event send. Returns in O(microseconds) on the hot path:
     * the actual HTTP transaction is multiplexed by Guzzle and drained at
     * shutdown (`register_shutdown_function`) or via flushAsync().
     */
    public function sendAsync(array $payload): void
    {
        if (!$this->budget->withinBudget()) {
            $this->metrics->recordDrop('budget_exceeded');
            return;
        }

        try {
            $request = new Request(
                'POST',
                $this->eventUrl,
                [
                    'X-API-Key'    => $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            );

            $promise = $this->client->sendAsync($request, [
                'timeout'         => $this->timeout,
                'connect_timeout' => self::HOT_PATH_CONNECT_TIMEOUT_S,
            ])->then(
                null,
                function (\Throwable $e): void {
                    $this->metrics->recordSend(0.0, false, $e->getMessage());
                }
            );

            $this->pendingPromises[] = $promise;
            $this->metrics->recordAsync();
            $this->registerShutdownDrainOnce();
        } catch (\Throwable $e) {
            $this->metrics->recordDrop('async_setup_failed');
            $this->logger->error('[ErrorWatch] Failed to schedule async send: ' . $e->getMessage());
        }
    }

    /**
     * Drain all pending async promises. Called by host integrations at
     * kernel.terminate / middleware terminate, and as a fallback at PHP
     * shutdown.
     *
     * Uses wait(false) so any rejected promise does not propagate.
     */
    public function flushAsync(): void
    {
        if (empty($this->pendingPromises)) {
            return;
        }
        foreach ($this->pendingPromises as $promise) {
            try {
                $promise->wait(false);
            } catch (\Throwable) {
                // swallow — async errors are recorded via promise rejection handler
            }
        }
        $this->pendingPromises = [];
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
     * Reset per-request mutable state — call between requests under
     * Octane/RoadRunner. Does NOT clear the metrics counters.
     */
    public function resetState(): void
    {
        $this->pendingPromises = [];
        $this->budget->reset();
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
}
