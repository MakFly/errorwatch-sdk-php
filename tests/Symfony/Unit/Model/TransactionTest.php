<?php

namespace ErrorWatch\Symfony\Tests\Unit\Model;

use ErrorWatch\Symfony\Model\Span;
use ErrorWatch\Symfony\Model\Transaction;
use PHPUnit\Framework\TestCase;

final class TransactionTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $txn = new Transaction('GET api_users_list', 'http.server');

        $array = $txn->toArray();

        $this->assertSame('GET api_users_list', $array['name']);
        $this->assertSame('http.server', $array['op']);
        $this->assertSame('ok', $array['status']);
        $this->assertIsString($array['id']);
        $this->assertSame(32, strlen($array['id']));
        $this->assertIsInt($array['startTimestamp']);
        $this->assertNull($array['endTimestamp']);
    }

    public function testDefaultOpIsHttpServer(): void
    {
        $txn = new Transaction('GET /users');

        $this->assertSame('http.server', $txn->toArray()['op']);
    }

    public function testFinishSetsEndTimestamp(): void
    {
        $txn = new Transaction('GET /users');
        $txn->finish();

        $array = $txn->toArray();

        $this->assertIsInt($array['endTimestamp']);
        $this->assertGreaterThanOrEqual($array['startTimestamp'], $array['endTimestamp']);
    }

    public function testAddSpan(): void
    {
        $txn = new Transaction('GET /users');
        $span = new Span('db.sql.query', 'SELECT ?');
        $span->finish();

        $txn->addSpan($span);

        $array = $txn->toArray();
        $this->assertCount(1, $array['spans']);
        $this->assertSame('db.sql.query', $array['spans'][0]['op']);
    }

    public function testAddSpanRespectsMaxSpans(): void
    {
        $txn = new Transaction('GET /users');

        for ($i = 0; $i < 210; ++$i) {
            $span = new Span('db.sql.query');
            $span->finish();
            $txn->addSpan($span);
        }

        $this->assertCount(200, $txn->toArray()['spans']);
    }

    public function testSetTag(): void
    {
        $txn = new Transaction('GET /users');
        $txn->setTag('http.method', 'GET');
        $txn->setTag('http.status_code', '200');

        $array = $txn->toArray();

        $this->assertSame('GET', $array['tags']['http.method']);
        $this->assertSame('200', $array['tags']['http.status_code']);
    }

    public function testSetData(): void
    {
        $txn = new Transaction('GET /users');
        $txn->setData('http.url', '/api/users');

        $this->assertSame('/api/users', $txn->toArray()['data']['http.url']);
    }

    public function testGetDurationMs(): void
    {
        $txn = new Transaction('GET /users');
        usleep(1000); // 1ms
        $txn->finish();

        $this->assertGreaterThanOrEqual(0, $txn->getDurationMs());
    }

    public function testToArrayReturnsCompleteFormat(): void
    {
        $txn = new Transaction('GET /users');
        $txn->setTag('http.method', 'GET');
        $txn->setData('http.url', '/api/users');
        $span = new Span('db.sql.query');
        $span->finish();
        $txn->addSpan($span);
        $txn->finish();

        $array = $txn->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('op', $array);
        $this->assertArrayHasKey('status', $array);
        $this->assertArrayHasKey('startTimestamp', $array);
        $this->assertArrayHasKey('endTimestamp', $array);
        $this->assertArrayHasKey('duration', $array);
        $this->assertArrayHasKey('spans', $array);
        $this->assertArrayHasKey('tags', $array);
        $this->assertArrayHasKey('data', $array);
    }
}
