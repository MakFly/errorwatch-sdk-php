<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Tests\Transport;

use ErrorWatch\Sdk\Transport\HttpTransport;
use ErrorWatch\Sdk\Transport\RequestBudget;
use ErrorWatch\Sdk\Transport\TransportMetrics;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the two host-app guard-rails:
 *
 *   1. sendAsync() returns in O(microseconds) — the SDK never blocks
 *      the request lifecycle, even when the API is slow / unreachable.
 *   2. The request budget short-circuits after N ms — once consumed,
 *      every subsequent event is dropped (no I/O at all).
 */
final class AsyncBudgetTest extends TestCase
{
    public function test_send_async_returns_without_waiting_on_network(): void
    {
        // Mock handler queues a 200 — but we never resolve it on the hot path.
        $mock    = new MockHandler([new Response(200), new Response(200), new Response(200)]);
        $stack   = HandlerStack::create($mock);
        $client  = new Client(['handler' => $stack]);

        $transport = new HttpTransport(
            endpoint: 'https://api.errorwatch.io',
            apiKey:   'k',
            timeout:  5,
            client:   $client,
            budget:   new RequestBudget(50),
        );

        $start = microtime(true);
        $transport->sendAsync(['event_id' => 'a']);
        $transport->sendAsync(['event_id' => 'b']);
        $transport->sendAsync(['event_id' => 'c']);
        $elapsedMs = (microtime(true) - $start) * 1000.0;

        // Three fire-and-forget calls should add up to well below the 50 ms
        // budget. We assert a comfortable 25 ms ceiling to keep the test
        // resilient on slow CI runners.
        $this->assertLessThan(25.0, $elapsedMs, "sendAsync() blocked for {$elapsedMs}ms");
        $this->assertSame(3, $transport->getMetrics()->asyncCount);
    }

    public function test_budget_exhaustion_drops_subsequent_events(): void
    {
        $budget = new RequestBudget(20); // 20 ms
        $metrics = new TransportMetrics();
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200)]))]);

        $transport = new HttpTransport(
            endpoint: 'https://api.errorwatch.io',
            apiKey:   'k',
            timeout:  5,
            client:   $client,
            budget:   $budget,
            metrics:  $metrics,
        );

        // Manually consume the entire budget to simulate prior slow I/O.
        $budget->consume(25.0);

        $transport->sendAsync(['event_id' => 'dropped']);
        $transport->send(['event_id' => 'dropped-sync']);

        $this->assertSame(0, $metrics->asyncCount);
        $this->assertSame(0, $metrics->sendCount);
        $this->assertSame(2, $metrics->dropCount);
        $this->assertSame(2, $metrics->budgetExceededCount);
    }

    public function test_reset_state_restores_budget_between_requests(): void
    {
        $budget = new RequestBudget(10);
        $transport = new HttpTransport(
            endpoint: 'https://api.errorwatch.io',
            apiKey:   'k',
            timeout:  5,
            budget:   $budget,
        );

        $budget->consume(50.0);
        $this->assertFalse($budget->withinBudget());

        $transport->resetState();
        $this->assertTrue($budget->withinBudget());
    }
}
