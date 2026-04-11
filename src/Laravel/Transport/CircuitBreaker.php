<?php
declare(strict_types=1);

namespace ErrorWatch\Laravel\Transport;

class CircuitBreaker
{
    private const STATE_CLOSED    = 'CLOSED';
    private const STATE_OPEN      = 'OPEN';
    private const STATE_HALF_OPEN = 'HALF_OPEN';

    private int     $failures       = 0;
    private string  $state          = self::STATE_CLOSED;
    private ?float  $openedAt       = null;

    public function __construct(
        private readonly int $failureThreshold = 5,
        private readonly int $cooldownSeconds  = 60,
    ) {}

    /**
     * Returns true if a request should be allowed through.
     * Transitions OPEN → HALF_OPEN once the cooldown has expired.
     */
    public function allowRequest(): bool
    {
        if ($this->state === self::STATE_CLOSED || $this->state === self::STATE_HALF_OPEN) {
            return true;
        }

        // STATE_OPEN: check whether cooldown has elapsed
        if ($this->openedAt !== null && (microtime(true) - $this->openedAt) >= $this->cooldownSeconds) {
            $this->state = self::STATE_HALF_OPEN;
            return true;
        }

        return false;
    }

    /**
     * Call after a successful request.
     * Always resets to CLOSED regardless of previous state.
     */
    public function recordSuccess(): void
    {
        $this->failures  = 0;
        $this->state     = self::STATE_CLOSED;
        $this->openedAt  = null;
    }

    /**
     * Call after a failed request (connection error or 5xx).
     * Switches to OPEN once the failure threshold is reached.
     */
    public function recordFailure(): void
    {
        $this->failures++;

        if ($this->failures >= $this->failureThreshold) {
            $this->state    = self::STATE_OPEN;
            $this->openedAt = microtime(true);
        }
    }

    /**
     * Returns true when the circuit is OPEN (requests are blocked).
     */
    public function isOpen(): bool
    {
        return $this->state === self::STATE_OPEN;
    }

    /**
     * Full state reset — intended for Laravel Octane between-request cleanup.
     */
    public function reset(): void
    {
        $this->failures  = 0;
        $this->state     = self::STATE_CLOSED;
        $this->openedAt  = null;
    }
}
