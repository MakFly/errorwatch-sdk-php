<?php

namespace ErrorWatch\Laravel\Tests\Unit;

use ErrorWatch\Laravel\Tests\TestCase;
use ErrorWatch\Laravel\Tracing\Span;
use ErrorWatch\Laravel\Tracing\TraceContext;
use PHPUnit\Framework\Attributes\Test;

class SpanTest extends TestCase
{
    #[Test]
    public function it_can_create_span(): void
    {
        $context = TraceContext::generate();
        $span = new Span('test-span', $context);

        $this->assertEquals('test-span', $span->toArray()['name']);
        $this->assertFalse($span->isFinished());
    }

    #[Test]
    public function it_can_set_tags(): void
    {
        $context = TraceContext::generate();
        $span = new Span('test', $context);

        $span->setTag('key1', 'value1');
        $span->setTag('key2', 123);

        $this->assertEquals('value1', $span->getTag('key1'));
        $this->assertEquals(123, $span->getTag('key2'));
    }

    #[Test]
    public function it_can_set_multiple_tags(): void
    {
        $context = TraceContext::generate();
        $span = new Span('test', $context);

        $span->setTags(['key1' => 'value1', 'key2' => 'value2']);

        $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], $span->getTags());
    }

    #[Test]
    public function it_can_set_data(): void
    {
        $context = TraceContext::generate();
        $span = new Span('test', $context);

        $span->setData('custom', ['foo' => 'bar']);

        $this->assertEquals(['foo' => 'bar'], $span->getData()['custom']);
    }

    #[Test]
    public function it_can_set_status(): void
    {
        $context = TraceContext::generate();
        $span = new Span('test', $context);

        $span->setStatus('ok');

        $this->assertEquals('ok', $span->getStatus());
    }

    #[Test]
    public function it_can_set_ok_status(): void
    {
        $context = TraceContext::generate();
        $span = new Span('test', $context);

        $span->setOk();

        $this->assertEquals('ok', $span->getStatus());
    }

    #[Test]
    public function it_can_set_error_status(): void
    {
        $context = TraceContext::generate();
        $span = new Span('test', $context);

        $span->setError('Something went wrong');

        $this->assertEquals('error', $span->getStatus());
    }

    #[Test]
    public function it_can_finish_span(): void
    {
        $context = TraceContext::generate();
        $span = new Span('test', $context);

        $span->finish();

        $this->assertTrue($span->isFinished());
        $this->assertNotNull($span->getEndTimestamp());
    }

    #[Test]
    public function it_calculates_duration(): void
    {
        $context = TraceContext::generate();
        $span = new Span('test', $context);

        usleep(10000); // 10ms
        $span->finish();

        $this->assertGreaterThanOrEqual(10, $span->getDurationMs());
    }

    #[Test]
    public function it_can_start_child_span(): void
    {
        $context = TraceContext::generate();
        $parent = new Span('parent', $context);

        $child = $parent->startChild('child', 'operation');

        $this->assertEquals('child', $child->toArray()['name']);
        $this->assertEquals('operation', $child->getOp());
        $this->assertCount(1, $parent->getSpans());
    }

    #[Test]
    public function it_includes_child_spans_in_array(): void
    {
        $context = TraceContext::generate();
        $parent = new Span('parent', $context);

        $child = $parent->startChild('child');
        $child->finish();

        $parent->finish();

        $array = $parent->toArray();

        $this->assertArrayHasKey('spans', $array);
        $this->assertCount(1, $array['spans']);
    }

    #[Test]
    public function it_can_set_operation(): void
    {
        $context = TraceContext::generate();
        $span = new Span('test', $context);

        $span->setOp('http.server');

        $this->assertEquals('http.server', $span->getOp());
    }
}
