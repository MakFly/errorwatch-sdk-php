<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Tests\Unit;

use ErrorWatch\Laravel\Client\MonitoringClient;
use ErrorWatch\Laravel\Tests\TestCase;
use ErrorWatch\Laravel\Transport\QueueDispatchingTransport;
use ErrorWatch\Sdk\Client as SdkClient;
use PHPUnit\Framework\Attributes\Test;

/**
 * Verifies that the host application stays fast regardless of config:
 *
 *  - default `auto` mode resolves to `async` (no queue driver) or `queue`
 *    (real driver) — never `sync`, never blocking I/O on the request path.
 *  - explicit `sync` mode stays sync (worker / test use cases).
 *  - explicit `queue` mode wraps the transport so the core SDK's
 *    sendAsync() dispatches a Laravel job instead of issuing HTTP.
 */
final class TransportModeResolutionTest extends TestCase
{
    private function freshClient(): MonitoringClient
    {
        // MonitoringClient is registered as a singleton — flush it so the
        // new config applies on the next resolution.
        $this->app->forgetInstance(MonitoringClient::class);
        return $this->app->make(MonitoringClient::class);
    }

    #[Test]
    public function auto_mode_resolves_to_async_when_queue_driver_is_sync(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('errorwatch.transport.mode', 'auto');

        $client = $this->freshClient();
        $this->assertSame('async', $client->getConfig('transport.mode'));
    }

    #[Test]
    public function auto_mode_resolves_to_queue_when_real_driver_present(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('errorwatch.transport.mode', 'auto');

        $client = $this->freshClient();
        $this->assertSame('queue', $client->getConfig('transport.mode'));

        // The SDK delegate must be the queue-dispatching wrapper.
        $sdkClient = $client->getSdkClient();
        $reflectionTransport = (new \ReflectionClass(SdkClient::class))->getProperty('transport');
        $reflectionTransport->setAccessible(true);
        $delegate = $reflectionTransport->getValue($sdkClient);

        $this->assertInstanceOf(QueueDispatchingTransport::class, $delegate);
    }

    #[Test]
    public function explicit_sync_mode_is_respected(): void
    {
        config()->set('errorwatch.transport.mode', 'sync');

        $client = $this->freshClient();
        $this->assertSame('sync', $client->getConfig('transport.mode'));
    }
}
