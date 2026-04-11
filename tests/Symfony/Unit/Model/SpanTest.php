<?php

namespace ErrorWatch\Symfony\Tests\Unit\Model;

use ErrorWatch\Symfony\Model\Span;
use PHPUnit\Framework\TestCase;

final class SpanTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $span = new Span('db.sql.query', 'SELECT * FROM users');

        $array = $span->toArray();

        $this->assertSame('db.sql.query', $array['op']);
        $this->assertSame('SELECT * FROM users', $array['description']);
        $this->assertSame('ok', $array['status']);
        $this->assertIsString($array['id']);
        $this->assertSame(32, strlen($array['id']));
        $this->assertIsInt($array['startTimestamp']);
        $this->assertNull($array['endTimestamp']);
    }

    public function testConstructorWithNullDescription(): void
    {
        $span = new Span('db.sql.query');

        $array = $span->toArray();

        $this->assertNull($array['description']);
    }

    public function testFinishSetsEndTimestamp(): void
    {
        $span = new Span('db.sql.query');
        $this->assertNull($span->toArray()['endTimestamp']);

        $span->finish();

        $array = $span->toArray();
        $this->assertIsInt($array['endTimestamp']);
        $this->assertGreaterThanOrEqual($array['startTimestamp'], $array['endTimestamp']);
    }

    public function testSetStatus(): void
    {
        $span = new Span('db.sql.query');
        $span->setStatus('error');

        $this->assertSame('error', $span->toArray()['status']);
    }

    public function testSetData(): void
    {
        $span = new Span('db.sql.query');
        $span->setData('db.system', 'postgresql');

        $this->assertSame('postgresql', $span->toArray()['data']['db.system']);
    }

    public function testToArrayReturnsCorrectFormat(): void
    {
        $span = new Span('db.sql.query', 'SELECT ?');
        $span->setStatus('ok');
        $span->setData('db.system', 'postgresql');
        $span->finish();

        $array = $span->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('op', $array);
        $this->assertArrayHasKey('description', $array);
        $this->assertArrayHasKey('status', $array);
        $this->assertArrayHasKey('startTimestamp', $array);
        $this->assertArrayHasKey('endTimestamp', $array);
        $this->assertArrayHasKey('data', $array);
    }
}
