<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Transport;

use ErrorWatch\Laravel\Jobs\SendEventJob;
use ErrorWatch\Sdk\Transport\TransportInterface;

/**
 * Wraps the real HttpTransport so the SDK's hot-path async send
 * dispatches a Laravel job (Redis / database / SQS) instead of issuing
 * an HTTP call. The job ultimately calls the inner transport's sync
 * send() — but inside a worker, not inside the user's request.
 *
 * Used when `errorwatch.transport.mode` resolves to `queue`.
 */
final class QueueDispatchingTransport implements TransportInterface
{
    /** @var callable(): TransportInterface */
    private $resolver;

    public function __construct(
        callable $resolver,
        private readonly ?string $connection,
        private readonly string  $queueName,
    ) {
        $this->resolver = $resolver;
    }

    /**
     * Sync send: only reached from inside the worker (SendEventJob::handle).
     * Forwarding to the inner transport keeps the existing circuit-breaker
     * and metric paths intact.
     */
    public function send(array $payload): bool
    {
        return ($this->resolver)()->send($payload);
    }

    /**
     * Hot-path: dispatch a job instead of doing any I/O. If dispatch itself
     * fails (e.g. the queue connection is broken), fall back to the inner
     * transport's async path so we never silently lose every event.
     */
    public function sendAsync(array $payload): void
    {
        try {
            $job = new SendEventJob('event', $payload);
            if ($this->connection !== null) {
                $job->onConnection($this->connection);
            }
            $job->onQueue($this->queueName);

            // Use the dispatch() helper indirectly via the static facade-free
            // helper to remain testable; fall back if Laravel container is
            // not yet booted.
            if (function_exists('dispatch')) {
                \dispatch($job);
                return;
            }
        } catch (\Throwable $e) {
            error_log('[ErrorWatch] Queue dispatch failed, falling back to async HTTP: ' . $e->getMessage());
        }

        ($this->resolver)()->sendAsync($payload);
    }
}
