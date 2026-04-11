<?php

namespace ErrorWatch\Laravel\Tests\Unit;

use ErrorWatch\Laravel\Tests\TestCase;
use ErrorWatch\Laravel\Transport\HttpTransport;
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
}
