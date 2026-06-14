<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Tests\Unit;

use ErrorWatch\Laravel\Profiler\RequestProfile;
use ErrorWatch\Laravel\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
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

    public function test_refresh_updates_late_request_route_and_status_without_resetting_collectors(): void
    {
        $profile = new RequestProfile();
        $request = Request::create('/api/packages/distrib-app?tab=issues', 'GET', [], [
            'spatie_session' => 'secret-cookie-value',
        ], [], [
            'HTTP_AUTHORIZATION' => 'Bearer secret',
            'HTTP_COOKIE' => 'spatie_session=secret-cookie-value',
            'HTTP_USER_AGENT' => 'phpunit-browser',
        ]);

        $profile->start($request);
        $profile->recordQuery('SELECT * FROM packages WHERE slug = ?', ['distrib-app'], 12.0, 'mysql');
        $profile->recordCacheOp('hit', 'package:distrib-app', 'redis');
        $profile->recordView('packages.show', '/resources/views/packages/show.blade.php', ['package'], 4.2);

        $route = new Route(['GET'], 'api/packages/{package}', [
            'uses' => 'App\\Http\\Controllers\\PackageController@show',
            'controller' => 'App\\Http\\Controllers\\PackageController@show',
            'as' => 'api.packages.show',
            'middleware' => ['api', 'auth:sanctum'],
        ]);
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);

        $profile->refresh($request, new Response('OK', 202));
        $payload = $profile->toArray();

        $this->assertSame(202, $payload['status_code']);
        $this->assertSame('GET', $payload['method']);
        $this->assertSame('http://localhost/api/packages/distrib-app?tab=issues', $payload['url']);
        $this->assertSame('api.packages.show', $payload['route']['name']);
        $this->assertSame('App\\Http\\Controllers\\PackageController@show', $payload['route']['action']);
        $this->assertSame(['distrib-app'], array_values($payload['route']['parameters']));
        $this->assertContains('GET', $payload['route']['methods']);
        $this->assertSame(['spatie_session'], $payload['request']['cookies']);
        $this->assertNull($payload['request']['session']);
        $this->assertSame(1, $payload['queries']['total_count']);
        $this->assertSame(1, $payload['cache']['hits']);
        $this->assertSame(1, $payload['views']['total_count']);

        $headerKeys = array_map('strtolower', array_keys($payload['request']['headers']));
        $this->assertContains('user-agent', $headerKeys);
        $this->assertNotContains('authorization', $headerKeys);
        $this->assertNotContains('cookie', $headerKeys);
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
