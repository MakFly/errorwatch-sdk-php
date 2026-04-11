<?php
declare(strict_types=1);

namespace ErrorWatch\Laravel\Transport;

class RetryHandler
{
    public function __construct(
        private readonly int $maxRetries = 2,
    ) {}

    /**
     * Returns true when the request should be retried.
     *
     * @param int $attempt    0-based attempt index (0 = first attempt, 1 = first retry…)
     * @param int $statusCode HTTP status code received
     */
    public function shouldRetry(int $attempt, int $statusCode): bool
    {
        if ($attempt >= $this->maxRetries) {
            return false;
        }

        return $this->isRetryableStatus($statusCode);
    }

    /**
     * Returns the number of milliseconds to wait before the next attempt.
     *
     * If the server supplied a Retry-After value (in seconds) it takes priority.
     * Otherwise uses exponential back-off: min(2^attempt * 100, 5000) ms + rand(0, 100) ms jitter.
     *
     * @param int      $attempt            0-based attempt index
     * @param int|null $retryAfterSeconds  Value from the Retry-After response header, if present
     */
    public function getDelay(int $attempt, ?int $retryAfterSeconds = null): int
    {
        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            return $retryAfterSeconds * 1000; // convert to ms
        }

        $base  = min((int) (2 ** $attempt) * 100, 5000);
        $jitter = random_int(0, 100);

        return $base + $jitter;
    }

    /**
     * Returns true for HTTP status codes that warrant a retry.
     */
    public function isRetryableStatus(int $statusCode): bool
    {
        return $statusCode === 429
            || $statusCode === 503
            || ($statusCode >= 500 && $statusCode < 600);
    }
}
