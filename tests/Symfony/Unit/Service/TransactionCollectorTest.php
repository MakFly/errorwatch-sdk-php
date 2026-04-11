<?php

namespace ErrorWatch\Symfony\Tests\Unit\Service;

use ErrorWatch\Symfony\Model\Span;
use ErrorWatch\Symfony\Service\TransactionCollector;
use PHPUnit\Framework\TestCase;

final class TransactionCollectorTest extends TestCase
{
    private TransactionCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new TransactionCollector();
    }

    public function testStartTransactionCreatesTransaction(): void
    {
        $txn = $this->collector->startTransaction('GET /users');

        $this->assertNotNull($txn);
        $this->assertSame('GET /users', $txn->toArray()['name']);
    }

    public function testGetCurrentTransactionReturnsNullInitially(): void
    {
        $this->assertNull($this->collector->getCurrentTransaction());
    }

    public function testGetCurrentTransactionReturnsActiveTransaction(): void
    {
        $txn = $this->collector->startTransaction('GET /users');

        $this->assertSame($txn, $this->collector->getCurrentTransaction());
    }

    public function testHasTransactionReturnsFalseInitially(): void
    {
        $this->assertFalse($this->collector->hasTransaction());
    }

    public function testHasTransactionReturnsTrueWhenActive(): void
    {
        $this->collector->startTransaction('GET /users');

        $this->assertTrue($this->collector->hasTransaction());
    }

    public function testAddSpanRoutesToCurrentTransaction(): void
    {
        $txn = $this->collector->startTransaction('GET /users');
        $span = new Span('db.sql.query', 'SELECT ?');
        $span->finish();

        $this->collector->addSpan($span);

        $this->assertCount(1, $txn->toArray()['spans']);
    }

    public function testAddSpanNoOpWhenNoTransaction(): void
    {
        $span = new Span('db.sql.query');
        $span->finish();

        // Should not throw
        $this->collector->addSpan($span);

        $this->assertFalse($this->collector->hasTransaction());
    }

    public function testFinishTransactionReturnsTransactionAndClears(): void
    {
        $this->collector->startTransaction('GET /users');

        $txn = $this->collector->finishTransaction('ok');

        $this->assertNotNull($txn);
        $this->assertSame('ok', $txn->toArray()['status']);
        $this->assertFalse($this->collector->hasTransaction());
        $this->assertNull($this->collector->getCurrentTransaction());
    }

    public function testFinishTransactionReturnsNullWhenNoTransaction(): void
    {
        $this->assertNull($this->collector->finishTransaction());
    }

    public function testReset(): void
    {
        $this->collector->startTransaction('GET /users');
        $this->assertTrue($this->collector->hasTransaction());

        $this->collector->reset();

        $this->assertFalse($this->collector->hasTransaction());
    }
}
