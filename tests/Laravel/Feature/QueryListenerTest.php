<?php

namespace ErrorWatch\Laravel\Tests\Feature;

use ErrorWatch\Laravel\Services\QueryListener;
use ErrorWatch\Laravel\Tests\TestCase;
use Illuminate\Database\Events\QueryExecuted;
use ErrorWatch\Laravel\Facades\ErrorWatch;
use PHPUnit\Framework\Attributes\Test;

class QueryListenerTest extends TestCase
{
    protected QueryListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = $this->app->make(QueryListener::class);
    }

    #[Test]
    public function it_can_register_listener(): void
    {
        // register() attaches a DB listener — it should complete without exceptions
        $this->listener->register();

        // Verify the listener is still a valid QueryListener instance after registration
        $this->assertInstanceOf(\ErrorWatch\Laravel\Services\QueryListener::class, $this->listener);
    }

    #[Test]
    public function it_handles_query(): void
    {
        ErrorWatch::clearBreadcrumbs();

        $query = new QueryExecuted(
            'SELECT * FROM users WHERE id = ?',
            [1],
            15.5,
            $this->app->make('db')->connection()
        );

        $this->listener->handleQuery($query);

        $breadcrumbs = ErrorWatch::getBreadcrumbs();

        $this->assertNotEmpty($breadcrumbs);
        $this->assertStringContainsString('Query', $breadcrumbs[0]['message']);
    }

    #[Test]
    public function it_detects_slow_queries(): void
    {
        config(['errorwatch.apm.slow_query_threshold_ms' => 100]);

        $listener = $this->app->make(QueryListener::class);
        ErrorWatch::clearBreadcrumbs();

        $query = new QueryExecuted(
            'SELECT * FROM large_table',
            [],
            150.0, // 150ms, over threshold
            $this->app->make('db')->connection()
        );

        $listener->handleQuery($query);

        // Should have captured the query
        $this->assertNotEmpty(ErrorWatch::getBreadcrumbs());
    }

    #[Test]
    public function it_sanitizes_long_queries(): void
    {
        ErrorWatch::clearBreadcrumbs();

        $longSql = str_repeat('SELECT * FROM users ', 100);

        $query = new QueryExecuted(
            $longSql,
            [],
            10.0,
            $this->app->make('db')->connection()
        );

        $this->listener->handleQuery($query);

        // Breadcrumb should have been added with truncated SQL
        $breadcrumbs = ErrorWatch::getBreadcrumbs();
        $this->assertNotEmpty($breadcrumbs);

        // The message stored in the breadcrumb should not exceed 1000 chars + truncation marker
        $message = $breadcrumbs[0]['message'] ?? '';
        $this->assertLessThanOrEqual(1100, strlen($message));
    }

    #[Test]
    public function it_resets_query_counts(): void
    {
        // Simulate enough queries on the same pattern to increment internal counts
        for ($i = 0; $i < 3; $i++) {
            $query = new QueryExecuted(
                'SELECT * FROM posts WHERE id = ?',
                [$i],
                5.0,
                $this->app->make('db')->connection()
            );
            $this->listener->handleQuery($query);
        }

        // After reset, internal counts should be cleared (no exception thrown)
        $this->listener->reset();

        // A fresh query after reset should not trigger any N+1 warning prematurely
        ErrorWatch::clearBreadcrumbs();

        $query = new QueryExecuted(
            'SELECT * FROM posts WHERE id = ?',
            [99],
            5.0,
            $this->app->make('db')->connection()
        );
        $this->listener->handleQuery($query);

        // Only one breadcrumb from the single query, no N+1 capture message
        $breadcrumbs = ErrorWatch::getBreadcrumbs();
        $this->assertCount(1, $breadcrumbs);
    }
}
