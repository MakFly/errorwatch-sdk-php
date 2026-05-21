<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Tests\Unit;

use ErrorWatch\Laravel\Client\MonitoringClient;
use ErrorWatch\Laravel\Jobs\SendEventJob;
use ErrorWatch\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;

/**
 * In `queue` mode every send path that bypasses the core SDK pipeline —
 * custom events, APM transactions and live logs — must dispatch a
 * SendEventJob instead of issuing in-request HTTP. Otherwise queue mode is
 * silently defeated for those paths.
 */
final class QueueRoutingTest extends TestCase
{
    private function queueModeClient(): MonitoringClient
    {
        config()->set('queue.default', 'redis');
        config()->set('errorwatch.transport.mode', 'queue');

        // MonitoringClient is a singleton — drop it so the new mode applies.
        $this->app->forgetInstance(MonitoringClient::class);

        return $this->app->make(MonitoringClient::class);
    }

    #[Test]
    public function capture_event_dispatches_a_job_in_queue_mode(): void
    {
        Bus::fake();
        $client = $this->queueModeClient();

        $client->captureEvent(['event_id' => 'evt-1', 'message' => 'custom']);

        Bus::assertDispatched(
            SendEventJob::class,
            fn (SendEventJob $job): bool => $job->kind === 'event',
        );
    }

    #[Test]
    public function finish_transaction_dispatches_a_job_in_queue_mode(): void
    {
        Bus::fake();
        $client = $this->queueModeClient();

        $client->startTransaction('GET /orders');
        $client->finishTransaction();

        Bus::assertDispatched(
            SendEventJob::class,
            fn (SendEventJob $job): bool => $job->kind === 'transaction',
        );
    }

    #[Test]
    public function deliver_log_dispatches_a_job_in_queue_mode(): void
    {
        Bus::fake();
        $client = $this->queueModeClient();

        $client->deliverLog(['level' => 'error', 'message' => 'boom']);

        Bus::assertDispatched(
            SendEventJob::class,
            fn (SendEventJob $job): bool => $job->kind === 'log',
        );
    }

    #[Test]
    public function async_mode_does_not_dispatch_any_job(): void
    {
        Bus::fake();

        config()->set('queue.default', 'sync');
        config()->set('errorwatch.transport.mode', 'async');
        $this->app->forgetInstance(MonitoringClient::class);
        $client = $this->app->make(MonitoringClient::class);

        $client->captureEvent(['event_id' => 'evt-2', 'message' => 'custom']);

        Bus::assertNotDispatched(SendEventJob::class);
    }
}
