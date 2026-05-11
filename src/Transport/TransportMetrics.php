<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Transport;

/**
 * Mutable runtime metrics for a transport instance.
 *
 * Exposed for observability/debug (e.g. `php artisan errorwatch:stats`),
 * never read on the hot path. Counters are plain ints, no locks — single
 * PHP process per request, so concurrent writes don't happen here.
 */
final class TransportMetrics
{
    public int $sendCount = 0;
    public int $asyncCount = 0;
    public int $queuedCount = 0;
    public int $dropCount = 0;
    public int $budgetExceededCount = 0;
    public int $circuitOpenCount = 0;
    public int $errorCount = 0;
    public float $totalDurationMs = 0.0;
    public ?string $lastError = null;
    public float $lastSendAt = 0.0;

    public function recordSend(float $durationMs, bool $ok, ?string $error = null): void
    {
        $this->sendCount++;
        $this->totalDurationMs += $durationMs;
        $this->lastSendAt = microtime(true);
        if (!$ok) {
            $this->errorCount++;
            if ($error !== null) {
                $this->lastError = $error;
            }
        }
    }

    public function recordAsync(): void
    {
        $this->asyncCount++;
        $this->lastSendAt = microtime(true);
    }

    public function recordQueued(): void
    {
        $this->queuedCount++;
    }

    public function recordDrop(string $reason): void
    {
        $this->dropCount++;
        $this->lastError = $reason;
        if ($reason === 'budget_exceeded') {
            $this->budgetExceededCount++;
        } elseif ($reason === 'circuit_open') {
            $this->circuitOpenCount++;
        }
    }

    /**
     * @return array<string, int|float|string|null>
     */
    public function toArray(): array
    {
        return [
            'send_count'             => $this->sendCount,
            'async_count'            => $this->asyncCount,
            'queued_count'           => $this->queuedCount,
            'drop_count'             => $this->dropCount,
            'budget_exceeded_count'  => $this->budgetExceededCount,
            'circuit_open_count'     => $this->circuitOpenCount,
            'error_count'            => $this->errorCount,
            'total_duration_ms'      => round($this->totalDurationMs, 2),
            'last_error'             => $this->lastError,
            'last_send_at'           => $this->lastSendAt,
        ];
    }

    public function reset(): void
    {
        $this->sendCount = 0;
        $this->asyncCount = 0;
        $this->queuedCount = 0;
        $this->dropCount = 0;
        $this->budgetExceededCount = 0;
        $this->circuitOpenCount = 0;
        $this->errorCount = 0;
        $this->totalDurationMs = 0.0;
        $this->lastError = null;
        $this->lastSendAt = 0.0;
    }
}
