<?php

namespace ErrorWatch\Laravel\Tests\Feature;

use ErrorWatch\Laravel\Tests\TestCase;
use ErrorWatch\Laravel\Client\MonitoringClient;
use ErrorWatch\Laravel\Profiler\RequestProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use ErrorWatch\Laravel\Http\Middleware\ErrorWatchMiddleware;
use ErrorWatch\Laravel\Facades\ErrorWatch;
use RuntimeException;
use PHPUnit\Framework\Attributes\Test;

class MiddlewareTest extends TestCase
{
    #[Test]
    public function it_starts_transaction_on_request(): void
    {
        $middleware = $this->app->make(ErrorWatchMiddleware::class);
        $request = Request::create('/test', 'GET');

        $response = $middleware->handle($request, function ($req) {
            return new Response('OK', 200);
        });

        // Transaction should have been started
        $this->assertEquals(200, $response->getStatusCode());
    }

    #[Test]
    public function it_captures_exceptions(): void
    {
        $middleware = $this->app->make(ErrorWatchMiddleware::class);
        $request = Request::create('/test', 'GET');

        $exception = new RuntimeException('Test exception');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Test exception');

        try {
            $middleware->handle($request, function ($req) use ($exception) {
                throw $exception;
            });
        } catch (RuntimeException $e) {
            // Exception should have been captured
            throw $e;
        }
    }

    #[Test]
    public function it_adds_breadcrumbs(): void
    {
        ErrorWatch::clearBreadcrumbs();

        $middleware = $this->app->make(ErrorWatchMiddleware::class);
        $request = Request::create('/api/test', 'POST');

        $middleware->handle($request, function ($req) {
            return new Response('OK', 200);
        });

        $breadcrumbs = ErrorWatch::getBreadcrumbs();

        $this->assertNotEmpty($breadcrumbs);
        $this->assertStringContainsString('POST', $breadcrumbs[0]['message'] ?? '');
    }

    #[Test]
    public function it_sets_user_context_when_authenticated(): void
    {
        // Mock authenticated user
        $user = new class {
            public function getAuthIdentifier() { return 999; }
            public $email = 'auth@example.com';
            public $name = 'Auth User';
        };

        $request = Request::create('/test', 'GET');
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $middleware = $this->app->make(ErrorWatchMiddleware::class);

        $middleware->handle($request, function ($req) {
            return new Response('OK', 200);
        });

        // The middleware should have set the user context from the request
        $userContext = $this->client->getUser();
        $this->assertNotNull($userContext);
        $this->assertEquals(999, $userContext['id']);
        $this->assertEquals('auth@example.com', $userContext['email']);
    }

    #[Test]
    public function it_maps_a_4xx_response_to_canonical_error_status(): void
    {
        $middleware = $this->app->make(ErrorWatchMiddleware::class);
        $request    = Request::create('/missing', 'GET');

        $middleware->handle($request, fn ($req) => new Response('Not Found', 404));

        // Hold the live Span reference; terminate() mutates it then clears the
        // client pointer, but the object we hold keeps the final status.
        $transaction = $this->client->getCurrentTransaction();
        $this->assertNotNull($transaction);

        $middleware->terminate($request, new Response('Not Found', 404));

        $this->assertSame('error', $transaction->getStatus());
        $this->assertNotSame('unknown_error', $transaction->getStatus());
    }

    #[Test]
    public function it_skips_excluded_routes(): void
    {
        // Build a dedicated MonitoringClient that already knows about the excluded route.
        // The existing $this->client is a singleton constructed before this test runs,
        // so its internal config array would not reflect a config() override.
        $clientWithExclusion = new \ErrorWatch\Laravel\Client\MonitoringClient(
            array_merge(config('errorwatch'), [
                'apm' => array_merge(config('errorwatch.apm'), [
                    'excluded_routes' => ['telescope/*'],
                ]),
            ])
        );

        $middleware = new ErrorWatchMiddleware($clientWithExclusion);
        $request    = Request::create('/telescope/requests', 'GET');

        $clientWithExclusion->clearBreadcrumbs();

        $middleware->handle($request, function ($req) {
            return new Response('OK', 200);
        });

        // The excluded route should have been skipped — no breadcrumbs added by the middleware
        $this->assertEmpty($clientWithExclusion->getBreadcrumbs());
    }

    #[Test]
    public function it_refreshes_profiler_with_resolved_api_route_response_status_and_user(): void
    {
        app(RequestProfile::class)->reset();

        $clientWithProfiler = new MonitoringClient(array_merge(config('errorwatch'), [
            'profiler' => array_merge(config('errorwatch.profiler'), [
                'enabled' => true,
            ]),
            'environment' => 'testing',
            'release' => '1.2.3',
            'server_name' => 'phpunit-host',
            'git' => [
                'commit' => 'abc1234',
                'branch' => 'feature/profiler',
                'dirty' => 'false',
            ],
        ]));

        $middleware = new ErrorWatchMiddleware($clientWithProfiler);
        $request = Request::create('/api/packages/distrib-app?tab=issues', 'GET', [], [
            'spatie_session' => 'secret-cookie-value',
        ], [], [
            'HTTP_AUTHORIZATION' => 'Bearer secret',
            'HTTP_COOKIE' => 'spatie_session=secret-cookie-value',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 PHPUnit Browser',
        ]);

        $user = new class {
            public function getAuthIdentifier() { return 123; }
            public $email = 'profiler@example.com';
            public $name = 'Profiler User';
        };

        $response = $middleware->handle($request, function (Request $req) use ($user) {
            $route = new Route(['GET'], 'api/packages/{package}', [
                'uses' => 'App\\Http\\Controllers\\PackageController@show',
                'controller' => 'App\\Http\\Controllers\\PackageController@show',
                'as' => 'api.packages.show',
                'middleware' => ['api', 'auth:sanctum'],
            ]);
            $route->bind($req);

            $req->setRouteResolver(static fn () => $route);
            $req->setUserResolver(static fn () => $user);

            return new Response('OK', 202);
        });

        $payload = app(RequestProfile::class)->toArray();
        $userContext = $clientWithProfiler->getUser();
        $scope = $clientWithProfiler->getSdkClient()->getScope();

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame(202, $payload['status_code']);
        $this->assertSame('api.packages.show', $payload['route']['name']);
        $this->assertSame('App\\Http\\Controllers\\PackageController@show', $payload['route']['action']);
        $this->assertContains('auth:sanctum', $payload['route']['middleware']);
        $this->assertSame(['distrib-app'], array_values($payload['route']['parameters']));
        $this->assertSame('Mozilla/5.0 PHPUnit Browser', $payload['request']['headers']['user-agent'][0] ?? null);
        $this->assertArrayNotHasKey('authorization', $payload['request']['headers']);
        $this->assertArrayNotHasKey('cookie', $payload['request']['headers']);
        $this->assertSame(['spatie_session'], $payload['request']['cookies']);
        $this->assertNull($payload['request']['session']);
        $this->assertSame(0, $payload['views']['total_count']);
        $this->assertSame(123, $userContext['id']);
        $this->assertSame('profiler@example.com', $userContext['email']);
        $this->assertSame('1.2.3', $scope->getTags()['release'] ?? null);
        $this->assertSame('abc1234', $scope->getTags()['commit'] ?? null);
        $this->assertSame('feature/profiler', $scope->getTags()['branch'] ?? null);
        $this->assertSame('phpunit-host', $scope->getExtras()['application']['server_name'] ?? null);
    }
}
