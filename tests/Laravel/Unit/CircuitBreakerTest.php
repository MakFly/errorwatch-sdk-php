<?php
declare(strict_types=1);

namespace ErrorWatch\Laravel\Tests\Unit;

use ErrorWatch\Laravel\Transport\CircuitBreaker;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CircuitBreakerTest extends TestCase
{
    #[Test]
    public function it_starts_in_closed_state(): void
    {
        $cb = new CircuitBreaker(failureThreshold: 5, cooldownSeconds: 60);

        $this->assertTrue($cb->allowRequest());
        $this->assertFalse($cb->isOpen());
    }

    #[Test]
    public function it_opens_after_reaching_failure_threshold(): void
    {
        $cb = new CircuitBreaker(failureThreshold: 5, cooldownSeconds: 60);

        // 4 failures should NOT open the circuit
        for ($i = 0; $i < 4; $i++) {
            $cb->recordFailure();
        }
        $this->assertTrue($cb->allowRequest());
        $this->assertFalse($cb->isOpen());

        // 5th failure reaches the threshold — circuit opens
        $cb->recordFailure();
        $this->assertFalse($cb->allowRequest());
        $this->assertTrue($cb->isOpen());
    }

    #[Test]
    public function it_transitions_to_half_open_after_cooldown(): void
    {
        // Use a 1-second cooldown so the test doesn't have to wait long
        $cb = new CircuitBreaker(failureThreshold: 3, cooldownSeconds: 1);

        for ($i = 0; $i < 3; $i++) {
            $cb->recordFailure();
        }

        // Circuit is open immediately after threshold
        $this->assertFalse($cb->allowRequest());

        // Wait for cooldown to expire
        usleep(1_100_000); // 1.1 seconds

        // Now it should transition to HALF_OPEN and allow the request
        $this->assertTrue($cb->allowRequest());
        $this->assertFalse($cb->isOpen()); // HALF_OPEN, not OPEN
    }

    #[Test]
    public function it_resets_to_closed_on_success(): void
    {
        $cb = new CircuitBreaker(failureThreshold: 3, cooldownSeconds: 1);

        for ($i = 0; $i < 3; $i++) {
            $cb->recordFailure();
        }

        $this->assertTrue($cb->isOpen());

        // Wait for cooldown → HALF_OPEN
        usleep(1_100_000);
        $cb->allowRequest(); // trigger transition

        // A successful request in HALF_OPEN resets to CLOSED
        $cb->recordSuccess();

        $this->assertTrue($cb->allowRequest());
        $this->assertFalse($cb->isOpen());
    }

    #[Test]
    public function it_reopens_on_failure_in_half_open_state(): void
    {
        $cb = new CircuitBreaker(failureThreshold: 2, cooldownSeconds: 1);

        for ($i = 0; $i < 2; $i++) {
            $cb->recordFailure();
        }

        // Wait for cooldown → HALF_OPEN
        usleep(1_100_000);
        $cb->allowRequest(); // trigger transition to HALF_OPEN

        // One more failure while HALF_OPEN re-opens the circuit
        $cb->recordFailure();

        $this->assertFalse($cb->allowRequest());
        $this->assertTrue($cb->isOpen());
    }

    #[Test]
    public function it_resets_all_state_on_reset(): void
    {
        $cb = new CircuitBreaker(failureThreshold: 3, cooldownSeconds: 60);

        for ($i = 0; $i < 3; $i++) {
            $cb->recordFailure();
        }

        $this->assertTrue($cb->isOpen());

        // reset() should restore a clean CLOSED state
        $cb->reset();

        $this->assertTrue($cb->allowRequest());
        $this->assertFalse($cb->isOpen());
    }

    #[Test]
    public function it_allows_requests_between_failures_below_threshold(): void
    {
        $cb = new CircuitBreaker(failureThreshold: 5, cooldownSeconds: 60);

        // Record failures below threshold, interspersed with successes
        $cb->recordFailure();
        $cb->recordFailure();
        $cb->recordSuccess(); // resets counter to 0

        // After success, failures count resets — need 5 more to open
        $cb->recordFailure();
        $cb->recordFailure();
        $cb->recordFailure();
        $cb->recordFailure();

        // Only 4 failures since last success — still CLOSED
        $this->assertTrue($cb->allowRequest());
    }
}
