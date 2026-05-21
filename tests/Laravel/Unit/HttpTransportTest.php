<?php

namespace ErrorWatch\Laravel\Tests\Unit;

use ErrorWatch\Laravel\Tests\TestCase;
use ErrorWatch\Laravel\Transport\HttpTransport;
use ErrorWatch\Sdk\Transport\RequestBudget;
use PHPUnit\Framework\Attributes\Test;

class HttpTransportTest extends TestCase
{
    #[Test]
    public function it_can_create_transport(): void
    {
        $transport = new HttpTransport('https://test.errorwatch.io', 'test-key');

        $this->assertTrue($transport->isConfigured());
    }

    #[Test]
    public function it_returns_false_when_not_configured(): void
    {
        $transport = new HttpTransport('', '');

        $this->assertFalse($transport->isConfigured());
    }

    #[Test]
    public function it_can_queue_events(): void
    {
        $transport = new HttpTransport('https://test.errorwatch.io', 'test-key');

        $transport->queue(['event' => 1]);
        $transport->queue(['event' => 2]);

        // Access pendingEvents via reflection to verify queuing
        $reflection = new \ReflectionClass($transport);
        $property = $reflection->getProperty('pendingEvents');
        $property->setAccessible(true);
        $pending = $property->getValue($transport);

        $this->assertCount(2, $pending);
        $this->assertEquals(['event' => 1], $pending[0]);
        $this->assertEquals(['event' => 2], $pending[1]);
    }

    #[Test]
    public function it_can_clear_queue_on_flush(): void
    {
        $transport = new HttpTransport('https://test.errorwatch.io', 'test-key');

        $transport->queue(['event' => 1]);

        // Flush will try to send but fail due to no network; queue should be cleared regardless
        $transport->flush();

        // Verify pendingEvents is empty after flush
        $reflection = new \ReflectionClass($transport);
        $property = $reflection->getProperty('pendingEvents');
        $property->setAccessible(true);
        $pending = $property->getValue($transport);

        $this->assertEmpty($pending);
    }

    #[Test]
    public function send_log_consumes_the_request_budget(): void
    {
        // Large budget so the withinBudget() guard never trips — we want to
        // observe the consumption itself (the new behaviour from B5).
        $budget = new RequestBudget(60_000);
        $transport = new HttpTransport('https://test.errorwatch.io', 'k', 1, 3, 30, 2, 50, $budget);

        $this->assertSame(0.0, $budget->consumed());

        // No network in tests — the POST fails fast, hitting the catch path,
        // which must still account for the elapsed time against the budget.
        $transport->sendLog(['level' => 'error', 'message' => 'x']);

        $this->assertGreaterThan(
            0.0,
            $budget->consumed(),
            'sendLog() must consume the request budget so the budget guard can trip',
        );
    }

    #[Test]
    public function send_transaction_consumes_the_request_budget(): void
    {
        $budget = new RequestBudget(60_000);
        $transport = new HttpTransport('https://test.errorwatch.io', 'k', 1, 3, 30, 2, 50, $budget);

        $this->assertSame(0.0, $budget->consumed());

        $transport->sendTransaction(['name' => 'GET /x'], 'production');

        $this->assertGreaterThan(
            0.0,
            $budget->consumed(),
            'sendTransaction() must consume the request budget so the budget guard can trip',
        );
    }

    #[Test]
    public function send_log_is_dropped_once_the_budget_is_exhausted(): void
    {
        $budget = new RequestBudget(50);
        $budget->consume(999.0); // exhausted by prior I/O on the same worker
        $transport = new HttpTransport('https://test.errorwatch.io', 'k', 1, 3, 30, 2, 50, $budget);

        $this->assertFalse($transport->sendLog(['level' => 'error', 'message' => 'x']));
    }
}
