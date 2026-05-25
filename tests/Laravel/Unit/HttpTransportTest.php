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

    #[Test]
    public function it_short_circuits_send_while_a_rate_limit_window_is_active(): void
    {
        $transport = new HttpTransport('https://test.errorwatch.io', 'test-key');

        // Force an active server rate-limit window via reflection.
        $ref = new \ReflectionClass($transport);
        $rem = $ref->getProperty('rateLimitRemaining');
        $rem->setAccessible(true);
        $rem->setValue($transport, 0);
        $reset = $ref->getProperty('rateLimitResetAt');
        $reset->setAccessible(true);
        $reset->setValue($transport, time() + 60);

        // send() must back off (return false) without ever hitting the network.
        $this->assertFalse($transport->send(['event_id' => 'x', 'level' => 'error']));
    }

    #[Test]
    public function note_rate_limit_opens_a_window_on_429_with_retry_after(): void
    {
        $transport = new HttpTransport('https://test.errorwatch.io', 'test-key');
        $ref       = new \ReflectionClass($transport);
        $note      = $ref->getMethod('noteRateLimit');
        $note->setAccessible(true);
        $isLimited = $ref->getMethod('isRateLimited');
        $isLimited->setAccessible(true);

        $response = new \GuzzleHttp\Psr7\Response(429, ['Retry-After' => '30']);
        $note->invoke($transport, $response, 429);

        $this->assertTrue($isLimited->invoke($transport));
    }

    #[Test]
    public function note_rate_limit_honours_remaining_zero_on_2xx(): void
    {
        $transport = new HttpTransport('https://test.errorwatch.io', 'test-key');
        $ref       = new \ReflectionClass($transport);
        $note      = $ref->getMethod('noteRateLimit');
        $note->setAccessible(true);
        $isLimited = $ref->getMethod('isRateLimited');
        $isLimited->setAccessible(true);

        $response = new \GuzzleHttp\Psr7\Response(200, [
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset'     => (string) (time() + 60),
        ]);
        $note->invoke($transport, $response, 200);

        $this->assertTrue($isLimited->invoke($transport));
    }

    #[Test]
    public function batch_mode_accumulates_items_instead_of_sending(): void
    {
        $transport = new HttpTransport('https://test.errorwatch.io', 'test-key');
        $transport->enableBatchMode();

        $transport->sendAsync(['event_id' => 'e1', 'level' => 'error']);
        $transport->sendLogAsync(['level' => 'warning', 'message' => 'x']);
        $transport->sendTransactionAsync(['name' => 'GET /x'], 'production');

        $ref = new \ReflectionClass($transport);
        $buf = $ref->getProperty('batchBuffer');
        $buf->setAccessible(true);
        $items = $buf->getValue($transport);

        $this->assertCount(3, $items);
        $this->assertSame('event', $items[0]['type']);
        $this->assertSame('log', $items[1]['type']);
        $this->assertSame('transaction', $items[2]['type']);
        $this->assertSame('GET /x', $items[2]['payload']['transaction']['name']);
    }

    #[Test]
    public function flush_batch_clears_the_buffer_even_when_the_send_fails(): void
    {
        $transport = new HttpTransport('https://test.errorwatch.io', 'test-key');
        $transport->enableBatchMode();
        $transport->sendAsync(['event_id' => 'e1', 'level' => 'error']);

        // Network is unreachable in tests; flush must still drain the buffer so
        // items never pile up unbounded.
        $transport->flushBatch();

        $ref = new \ReflectionClass($transport);
        $buf = $ref->getProperty('batchBuffer');
        $buf->setAccessible(true);

        $this->assertEmpty($buf->getValue($transport));
    }
}
