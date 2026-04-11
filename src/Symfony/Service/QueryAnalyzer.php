<?php

namespace ErrorWatch\Symfony\Service;

use ErrorWatch\Symfony\Model\Transaction;

final class QueryAnalyzer
{
    public function __construct(
        private int $nPlusOneThreshold = 5,
        private int $slowQueryThresholdMs = 500,
    ) {}

    /**
     * Analyze a transaction's spans for N+1 patterns and slow queries.
     *
     * @return array{tags: array<string, string>, data: array<string, mixed>}
     */
    public function analyze(Transaction $transaction): array
    {
        $spans = $transaction->getSpans();
        $dbSpans = array_filter($spans, fn($span) => $span->getOp() === 'db.sql.query');

        $tags = [];
        $data = [];
        $issues = [];

        // Group by normalized description
        $queryGroups = [];
        foreach ($dbSpans as $span) {
            $desc = $span->getDescription() ?? 'unknown';
            if (!isset($queryGroups[$desc])) {
                $queryGroups[$desc] = ['count' => 0, 'totalDurationMs' => 0];
            }
            $queryGroups[$desc]['count']++;
            $queryGroups[$desc]['totalDurationMs'] += $span->getDurationMs();
        }

        // Detect N+1
        $nPlusOneQueries = [];
        foreach ($queryGroups as $query => $stats) {
            if ($stats['count'] >= $this->nPlusOneThreshold) {
                $nPlusOneQueries[] = [
                    'query' => $query,
                    'count' => $stats['count'],
                    'totalDurationMs' => $stats['totalDurationMs'],
                ];
            }
        }

        if (!empty($nPlusOneQueries)) {
            $issues[] = 'n_plus_one';
            $data['n_plus_one_queries'] = $nPlusOneQueries;
        }

        // Detect slow queries
        $slowQueries = [];
        foreach ($dbSpans as $span) {
            if ($span->getDurationMs() >= $this->slowQueryThresholdMs) {
                $slowQueries[] = [
                    'query' => $span->getDescription() ?? 'unknown',
                    'durationMs' => $span->getDurationMs(),
                ];
            }
        }

        if (!empty($slowQueries)) {
            $issues[] = 'slow_query';
            $data['slow_queries'] = $slowQueries;
        }

        // Query stats
        $totalQueryTime = 0;
        foreach ($dbSpans as $span) {
            $totalQueryTime += $span->getDurationMs();
        }
        $data['query_stats'] = [
            'total' => count($dbSpans),
            'uniqueQueries' => count($queryGroups),
            'totalQueryTimeMs' => $totalQueryTime,
        ];

        if (!empty($issues)) {
            $tags['performance.issues'] = implode(',', $issues);
        }

        return ['tags' => $tags, 'data' => $data];
    }
}
