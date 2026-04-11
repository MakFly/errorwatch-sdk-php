<?php
declare(strict_types=1);

namespace ErrorWatch\Laravel\Tests\Unit;

use ErrorWatch\Laravel\Transport\RetryHandler;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class RetryHandlerTest extends TestCase
{
    #[Test]
    public function it_should_retry_429_within_max_retries(): void
    {
        $handler = new RetryHandler(maxRetries: 2);

        $this->assertTrue($handler->shouldRetry(0, 429));
        $this->assertTrue($handler->shouldRetry(1, 429));
    }

    #[Test]
    public function it_should_retry_503_within_max_retries(): void
    {
        $handler = new RetryHandler(maxRetries: 2);

        $this->assertTrue($handler->shouldRetry(0, 503));
        $this->assertTrue($handler->shouldRetry(1, 503));
    }

    #[Test]
    public function it_should_retry_500_within_max_retries(): void
    {
        $handler = new RetryHandler(maxRetries: 2);

        $this->assertTrue($handler->shouldRetry(0, 500));
        $this->assertTrue($handler->shouldRetry(1, 500));
    }

    #[Test]
    public function it_returns_false_when_attempt_reaches_max_retries(): void
    {
        $handler = new RetryHandler(maxRetries: 2);

        // attempt 2 == maxRetries → no more retries
        $this->assertFalse($handler->shouldRetry(2, 429));
        $this->assertFalse($handler->shouldRetry(2, 503));
        $this->assertFalse($handler->shouldRetry(2, 500));
    }

    #[Test]
    public function it_returns_false_when_attempt_exceeds_max_retries(): void
    {
        $handler = new RetryHandler(maxRetries: 2);

        $this->assertFalse($handler->shouldRetry(3, 503));
    }

    #[Test]
    public function it_returns_false_for_400_bad_request(): void
    {
        $handler = new RetryHandler(maxRetries: 2);

        $this->assertFalse($handler->shouldRetry(0, 400));
    }

    #[Test]
    public function it_returns_false_for_404_not_found(): void
    {
        $handler = new RetryHandler(maxRetries: 2);

        $this->assertFalse($handler->shouldRetry(0, 404));
    }

    #[Test]
    public function it_returns_false_for_401_unauthorized(): void
    {
        $handler = new RetryHandler(maxRetries: 2);

        $this->assertFalse($handler->shouldRetry(0, 401));
    }

    #[Test]
    public function it_uses_retry_after_header_in_milliseconds(): void
    {
        $handler = new RetryHandler(maxRetries: 2);

        // Retry-After: 5 seconds → 5000 ms
        $delay = $handler->getDelay(0, retryAfterSeconds: 5);
        $this->assertEquals(5000, $delay);

        // Retry-After: 30 seconds → 30000 ms
        $delay = $handler->getDelay(1, retryAfterSeconds: 30);
        $this->assertEquals(30000, $delay);
    }

    #[Test]
    public function it_uses_exponential_backoff_without_retry_after(): void
    {
        $handler = new RetryHandler(maxRetries: 3);

        // attempt 0 → base = min(2^0 * 100, 5000) = 100 ms + jitter [0-100]
        $delay0 = $handler->getDelay(0, retryAfterSeconds: null);
        $this->assertGreaterThanOrEqual(100, $delay0);
        $this->assertLessThanOrEqual(200, $delay0);

        // attempt 1 → base = min(2^1 * 100, 5000) = 200 ms + jitter [0-100]
        $delay1 = $handler->getDelay(1, retryAfterSeconds: null);
        $this->assertGreaterThanOrEqual(200, $delay1);
        $this->assertLessThanOrEqual(300, $delay1);

        // attempt 2 → base = min(2^2 * 100, 5000) = 400 ms + jitter [0-100]
        $delay2 = $handler->getDelay(2, retryAfterSeconds: null);
        $this->assertGreaterThanOrEqual(400, $delay2);
        $this->assertLessThanOrEqual(500, $delay2);
    }

    #[Test]
    public function it_caps_exponential_backoff_at_5000_ms(): void
    {
        $handler = new RetryHandler(maxRetries: 10);

        // attempt 10 → 2^10 * 100 = 102400 → capped at 5000 ms + jitter [0-100]
        $delay = $handler->getDelay(10, retryAfterSeconds: null);
        $this->assertGreaterThanOrEqual(5000, $delay);
        $this->assertLessThanOrEqual(5100, $delay);
    }

    #[Test]
    public function it_considers_429_retryable(): void
    {
        $handler = new RetryHandler();

        $this->assertTrue($handler->isRetryableStatus(429));
    }

    #[Test]
    public function it_considers_503_retryable(): void
    {
        $handler = new RetryHandler();

        $this->assertTrue($handler->isRetryableStatus(503));
    }

    #[Test]
    public function it_considers_all_5xx_retryable(): void
    {
        $handler = new RetryHandler();

        foreach ([500, 501, 502, 503, 504, 520, 599] as $status) {
            $this->assertTrue(
                $handler->isRetryableStatus($status),
                "Expected status {$status} to be retryable"
            );
        }
    }

    #[Test]
    public function it_does_not_consider_4xx_retryable_except_429(): void
    {
        $handler = new RetryHandler();

        foreach ([400, 401, 403, 404, 422] as $status) {
            $this->assertFalse(
                $handler->isRetryableStatus($status),
                "Expected status {$status} to NOT be retryable"
            );
        }
    }
}
