<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Tests\Unit;

use ErrorWatch\Laravel\Client\MonitoringClient;
use ErrorWatch\Laravel\Tests\TestCase;
use ErrorWatch\Laravel\Transport\HttpTransport;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * The middleware snapshots the HTTP request into the core SDK scope via
 * setRequestContext(). syncScopeToSdkClient() clears that scope before every
 * capture — it must preserve request / status_code so 4XX/5XX events keep
 * their HTTP context (the "ne remonte pas" prod bug), without leaking a
 * status code from a previous request on long-running workers.
 */
final class RequestContextTest extends TestCase
{
    /**
     * Swap the client transport with a mock that records the payloads it
     * receives, so we can assert what the built event actually carries.
     *
     * @param array<int, array<string, mixed>> $captured
     */
    private function recordingTransport(MonitoringClient $client, array &$captured): void
    {
        $mock = Mockery::mock(HttpTransport::class);
        $mock->shouldReceive('sendAsync')->andReturnUsing(function (array $p) use (&$captured): void {
            $captured[] = $p;
        });
        $mock->shouldReceive('send')->andReturnUsing(function (array $p) use (&$captured): bool {
            $captured[] = $p;
            return true;
        });

        $ref = new \ReflectionProperty($client, 'transport');
        $ref->setAccessible(true);
        $ref->setValue($client, $mock);
    }

    #[Test]
    public function captured_event_keeps_request_context_set_by_the_middleware(): void
    {
        $captured = [];
        $client = app(MonitoringClient::class);
        $this->recordingTransport($client, $captured);

        $client->setRequestContext([
            'url'    => 'https://app.test/orders/42',
            'method' => 'POST',
        ], 404);

        $client->captureMessage('order not found', 'error');

        $this->assertCount(1, $captured);
        $payload = $captured[0];

        // Before the fix, syncScopeToSdkClient()'s clear() wiped these.
        $this->assertSame('https://app.test/orders/42', $payload['request']['url']);
        $this->assertSame('POST', $payload['request']['method']);
        $this->assertSame(404, $payload['status_code']);
    }

    #[Test]
    public function entry_snapshot_does_not_leak_a_stale_status_code(): void
    {
        $captured = [];
        $client = app(MonitoringClient::class);
        $this->recordingTransport($client, $captured);

        // Request A finishes with 500 (middleware re-applies status on response).
        $client->setRequestContext(['url' => 'https://app.test/a', 'method' => 'GET'], 500);

        // Request B begins — the middleware entry snapshot carries no status yet.
        $client->setRequestContext(['url' => 'https://app.test/b', 'method' => 'GET'], null);

        // A capture before request B has produced a response must NOT be 500.
        $client->captureMessage('pre-response capture in request B', 'error');

        $this->assertCount(1, $captured);
        $payload = $captured[0];

        $this->assertSame('https://app.test/b', $payload['request']['url']);
        $this->assertArrayNotHasKey(
            'status_code',
            $payload,
            'a status code from a previous request must not leak into the next one',
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
