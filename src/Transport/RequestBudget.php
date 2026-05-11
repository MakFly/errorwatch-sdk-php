<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Transport;

/**
 * Wall-clock budget guard: hard cap on the total time the SDK may
 * spend on transport I/O during a single request lifecycle.
 *
 * The host application's response latency is the contract. Once the
 * budget is consumed, every subsequent transport call becomes a no-op,
 * the event is dropped, and a counter is incremented in TransportMetrics.
 *
 * Budget is reset between requests (Octane/RoadRunner) via reset().
 */
final class RequestBudget
{
    private float $startedAt;
    private float $consumedMs = 0.0;
    private readonly float $budgetMs;

    public function __construct(int $budgetMs = 50)
    {
        $this->budgetMs = max(0, (float) $budgetMs);
        $this->startedAt = microtime(true);
    }

    public function withinBudget(): bool
    {
        if ($this->budgetMs <= 0.0) {
            return true; // budget disabled
        }
        return $this->consumedMs < $this->budgetMs;
    }

    public function consume(float $ms): void
    {
        $this->consumedMs += max(0.0, $ms);
    }

    public function consumed(): float
    {
        return $this->consumedMs;
    }

    public function remaining(): float
    {
        if ($this->budgetMs <= 0.0) {
            return PHP_FLOAT_MAX;
        }
        return max(0.0, $this->budgetMs - $this->consumedMs);
    }

    public function budget(): float
    {
        return $this->budgetMs;
    }

    public function reset(): void
    {
        $this->consumedMs = 0.0;
        $this->startedAt = microtime(true);
    }
}
