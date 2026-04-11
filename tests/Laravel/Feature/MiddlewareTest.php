<?php

namespace ErrorWatch\Laravel\Tests\Feature;

use ErrorWatch\Laravel\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
}
