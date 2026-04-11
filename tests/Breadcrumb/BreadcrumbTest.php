<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Tests\Breadcrumb;

use ErrorWatch\Sdk\Breadcrumb\Breadcrumb;
use ErrorWatch\Sdk\Event\Severity;
use PHPUnit\Framework\TestCase;

class BreadcrumbTest extends TestCase
{
    public function test_basic_construction(): void
    {
        $bc = new Breadcrumb(category: 'test', message: 'hello');

        $this->assertSame('test', $bc->category);
        $this->assertSame('hello', $bc->message);
        $this->assertSame(Severity::INFO, $bc->level);
        $this->assertIsString($bc->timestamp);
    }

    public function test_to_array_basic(): void
    {
        $bc  = new Breadcrumb(category: 'log', message: 'msg', level: Severity::WARNING);
        $arr = $bc->toArray();

        $this->assertSame('log', $arr['category']);
        $this->assertSame('msg', $arr['message']);
        $this->assertSame('warning', $arr['level']);
        $this->assertArrayHasKey('timestamp', $arr);
    }

    public function test_to_array_omits_null_fields(): void
    {
        $bc  = new Breadcrumb(category: 'nav');
        $arr = $bc->toArray();

        $this->assertArrayNotHasKey('message', $arr);
        $this->assertArrayNotHasKey('type', $arr);
        $this->assertArrayNotHasKey('data', $arr);
    }

    public function test_factory_http(): void
    {
        $bc  = Breadcrumb::http('GET', 'https://example.com/api/users', 200);
        $arr = $bc->toArray();

        $this->assertSame('http', $arr['category']);
        $this->assertSame('http', $arr['type']);
        $this->assertSame('info', $arr['level']);
        $this->assertSame('GET', $arr['data']['method']);
        $this->assertSame('https://example.com/api/users', $arr['data']['url']);
        $this->assertSame(200, $arr['data']['status_code']);
    }

    public function test_factory_http_error_sets_error_level(): void
    {
        $bc = Breadcrumb::http('POST', '/api/login', 401);

        $this->assertSame(Severity::ERROR, $bc->level);
    }

    public function test_factory_navigation(): void
    {
        $bc  = Breadcrumb::navigation('/home', '/dashboard');
        $arr = $bc->toArray();

        $this->assertSame('navigation', $arr['category']);
        $this->assertSame('/home', $arr['data']['from']);
        $this->assertSame('/dashboard', $arr['data']['to']);
    }

    public function test_factory_query(): void
    {
        $bc  = Breadcrumb::query('SELECT * FROM users', 12.5);
        $arr = $bc->toArray();

        $this->assertSame('db.query', $arr['category']);
        $this->assertSame('SELECT * FROM users', $arr['message']);
        $this->assertSame(12.5, $arr['data']['duration_ms']);
    }

    public function test_factory_log(): void
    {
        $bc  = Breadcrumb::log('User logged in', Severity::INFO);
        $arr = $bc->toArray();

        $this->assertSame('log', $arr['category']);
        $this->assertSame('User logged in', $arr['message']);
        $this->assertSame('info', $arr['level']);
    }

    public function test_custom_timestamp_is_preserved(): void
    {
        $ts = '2026-01-01T12:00:00+00:00';
        $bc = new Breadcrumb(category: 'test', timestamp: $ts);

        $this->assertSame($ts, $bc->timestamp);
        $this->assertSame($ts, $bc->toArray()['timestamp']);
    }
}
