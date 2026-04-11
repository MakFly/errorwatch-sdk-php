<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Services;

use ErrorWatch\Laravel\Client\MonitoringClient;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QueryListener
{
    protected MonitoringClient $client;
    protected array $queryCounts = [];
    protected int $nPlusOneThreshold;
    protected int $slowQueryThresholdMs;
    protected bool $logQueries;

    public function __construct(MonitoringClient $client)
    {
        $this->client = $client;
        $this->nPlusOneThreshold = $client->getConfig('apm.n_plus_one_threshold', 5);
        $this->slowQueryThresholdMs = $client->getConfig('apm.slow_query_threshold_ms', 500);
        $this->logQueries = $client->getConfig('apm.eloquent.log_queries', true);
    }

    /**
     * Register the query listener.
     */
    public function register(): void
    {
        DB::listen(function (QueryExecuted $query) {
            $this->handleQuery($query);
        });
    }

    /**
     * Handle a database query.
     */
    public function handleQuery(QueryExecuted $query): void
    {
        if (!$this->client->isEnabled()) {
            return;
        }

        $sql = $query->sql;
        $bindings = $query->bindings;
        $timeMs = $query->time;
        $connection = $query->connectionName ?? 'default';

        // Add breadcrumb
        if ($this->client->getConfig('breadcrumbs.enabled', true)) {
            $this->client->getBreadcrumbManager()->addQuery(
                $this->sanitizeSql($sql),
                $timeMs,
                $connection,
                $bindings
            );
        }

        // Add to APM tracing
        if ($this->client->getConfig('apm.enabled', true)) {
            $transaction = $this->client->getCurrentTransaction();

            if ($transaction) {
                $sanitizedSql = $this->sanitizeSql($sql);
                $span = $transaction->startChild($sanitizedSql, 'db.sql.query');
                $span->setData('sql', $sanitizedSql);
                $span->setData('connection', $connection);
                $span->setData('bindings_count', count($bindings));
                $span->setTag('db.system', 'sql');

                // Check for slow query
                if ($timeMs > $this->slowQueryThresholdMs) {
                    $span->setTag('db.slow_query', true);
                }

                // Use Laravel's measured query time for accurate duration
                $span->finishWithDuration($timeMs);
            }
        }

        // Detect N+1 queries
        $this->detectNPlusOne($sql, $connection);
    }

    /**
     * Detect potential N+1 query problems.
     */
    protected function detectNPlusOne(string $sql, string $connection): bool
    {
        // Normalize the SQL to detect repeated patterns
        $normalizedSql = $this->normalizeSql($sql);
        $key = md5($normalizedSql . $connection);

        if (!isset($this->queryCounts[$key])) {
            $this->queryCounts[$key] = [
                'sql' => $normalizedSql,
                'count' => 0,
                'first_occurrence' => microtime(true),
            ];
        }

        $this->queryCounts[$key]['count']++;

        // Check threshold
        if ($this->queryCounts[$key]['count'] >= $this->nPlusOneThreshold) {
            // Report N+1 issue
            $this->client->captureMessage(
                "Potential N+1 query detected: {$normalizedSql}",
                'warning',
                [
                    'extra' => [
                        'query_count' => $this->queryCounts[$key]['count'],
                        'time_window_seconds' => microtime(true) - $this->queryCounts[$key]['first_occurrence'],
                        'connection' => $connection,
                    ],
                    'tags' => [
                        'issue_type' => 'n_plus_one',
                    ],
                ]
            );

            return true;
        }

        return false;
    }

    /**
     * Sanitize SQL for logging (remove sensitive data).
     */
    protected function sanitizeSql(string $sql): string
    {
        // Truncate very long queries
        if (strlen($sql) > 1000) {
            return substr($sql, 0, 1000) . '... [truncated]';
        }

        return $sql;
    }

    /**
     * Normalize SQL for N+1 detection (remove variable parts).
     */
    protected function normalizeSql(string $sql): string
    {
        // Remove quoted strings
        $normalized = preg_replace("/'[^']*'/", "'?'", $sql);

        // Remove numbers
        $normalized = preg_replace('/\b\d+\b/', '?', $normalized);

        // Normalize whitespace
        $normalized = preg_replace('/\s+/', ' ', trim($normalized));

        return $normalized;
    }

    /**
     * Reset query counts (call at the end of a request).
     */
    public function reset(): void
    {
        $this->queryCounts = [];
    }
}
