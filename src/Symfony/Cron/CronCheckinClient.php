<?php

namespace ErrorWatch\Symfony\Cron;

use ErrorWatch\Symfony\Http\MonitoringClientInterface;

/**
 * Thin wrapper around POST /api/v1/cron/checkin.
 * Call start() before the job, finish() after it, regardless of outcome.
 */
final class CronCheckinClient
{
    public function __construct(
        private readonly MonitoringClientInterface $client,
        private readonly ?string $environment = null,
    ) {
    }

    /**
     * Announce the start of a cron execution. Returns a checkin id that
     * must be passed back to finish() so the server can close the record.
     */
    public function start(string $slug, ?array $payload = null): ?string
    {
        $checkinId = bin2hex(random_bytes(8));

        $this->client->sendCronCheckin([
            'slug' => $slug,
            'status' => 'in_progress',
            'checkinId' => $checkinId,
            'env' => $this->environment,
            'payload' => $payload,
        ]);

        return $checkinId;
    }

    /**
     * Close a check-in with the final status. Duration is the elapsed
     * execution time in milliseconds.
     */
    public function finish(string $slug, string $checkinId, int $exitCode, int $durationMs, ?array $payload = null): void
    {
        $this->client->sendCronCheckin([
            'slug' => $slug,
            'status' => 0 === $exitCode ? 'ok' : 'error',
            'checkinId' => $checkinId,
            'duration' => $durationMs,
            'env' => $this->environment,
            'payload' => $payload,
        ]);
    }
}
