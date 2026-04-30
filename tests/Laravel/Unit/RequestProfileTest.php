<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Tests\Unit;

use ErrorWatch\Laravel\Profiler\RequestProfile;
use ErrorWatch\Laravel\Tests\TestCase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class RequestProfileTest extends TestCase
{
    public function test_starts_and_resets(): void
    {
        $profile = new RequestProfile();
        $this->assertFalse($profile->isStarted());

        $profile->start(Request::create('/test', 'GET'));
        $this->assertTrue($profile->isStarted());

        $profile->reset();
        $this->assertFalse($profile->isStarted());
    }

    public function test_records_query_with_slow_flag(): void
    {
        $profile = new RequestProfile();
        $profile->start(Request::create('/x', 'GET'));

        $profile->recordQuery('SELECT * FROM users WHERE name = ?', ['o\'brien'], 25.0, 'mysql', 100);
        $profile->recordQuery('SELECT * FROM heavy', [], 250.0, 'mysql', 100);

        $payload = $profile->toArray();
        $this->assertSame(2, $payload['queries']['total_count']);
        $this->assertSame(1, $payload['queries']['slow_count']);
        $this->assertSame(275.0, round($payload['queries']['total_time_ms'], 0));
        $this->assertStringContainsString("'o''brien'", $payload['queries']['items'][0]['bound_sql']);
    }

    public function test_detects_duplicate_queries(): void
    {
        $profile = new RequestProfile();
        $profile->start(Request::create('/x', 'GET'));

        $profile->recordQuery('SELECT * FROM users WHERE id = ?', [1], 10.0, 'mysql');
        $profile->recordQuery('SELECT * FROM users WHERE id = ?', [1], 10.0, 'mysql');
        $profile->recordQuery('SELECT * FROM users WHERE id = ?', [1], 10.0, 'mysql');

        $payload = $profile->toArray();
        $this->assertSame(1, $payload['queries']['duplicate_count']);
        $this->assertTrue($payload['queries']['items'][0]['is_duplicate']);
        $this->assertSame(3, $payload['queries']['items'][0]['duplicate_count']);
    }

    public function test_aggregates_cache_operations(): void
    {
        $profile = new RequestProfile();
        $profile->start(Request::create('/x', 'GET'));

        $profile->recordCacheOp('hit', 'foo', 'redis');
        $profile->recordCacheOp('hit', 'bar', 'redis');
        $profile->recordCacheOp('miss', 'baz', 'redis');
        $profile->recordCacheOp('write', 'qux', 'redis');

        $cache = $profile->toArray()['cache'];
        $this->assertSame(2, $cache['hits']);
        $this->assertSame(1, $cache['misses']);
        $this->assertSame(1, $cache['writes']);
        $this->assertSame(0, $cache['deletes']);
        $this->assertSame(66.7, $cache['hit_ratio']);
    }

    public function test_filters_sensitive_request_headers(): void
    {
        $profile = new RequestProfile();
        $request = Request::create('/x', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer secret',
            'HTTP_X_API_KEY' => 'k',
            'HTTP_USER_AGENT' => 'phpunit',
        ]);
        $profile->start($request);

        $headers = $profile->toArray()['request']['headers'];
        $headerKeys = array_map('strtolower', array_keys($headers));
        $this->assertNotContains('authorization', $headerKeys);
        $this->assertNotContains('x-api-key', $headerKeys);
        $this->assertContains('user-agent', $headerKeys);
    }

    public function test_resolves_status_code_from_http_exception(): void
    {
        $profile = new RequestProfile();
        $profile->start(Request::create('/boom', 'GET'));

        $payload = $profile->toArray(new HttpException(404, 'Not found'));
        $this->assertSame(404, $payload['status_code']);
    }

    public function test_records_logs_with_level_counts(): void
    {
        $profile = new RequestProfile();
        $profile->start(Request::create('/x', 'GET'));

        $profile->recordLog('info', 'one');
        $profile->recordLog('error', 'two');
        $profile->recordLog('error', 'three');

        $logs = $profile->toArray()['logs'];
        $this->assertSame(3, $logs['total_count']);
        $this->assertSame(2, $logs['counts_by_level']['error']);
        $this->assertSame('error', $logs['highest_level']);
        $this->assertSame(2, $logs['error_count']);
    }
}
