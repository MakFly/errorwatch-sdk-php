<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Tracing;

class RequestTracer
{
    protected ?Span $currentSpan = null;
    protected array $spans = [];
    protected ?TraceContext $traceContext = null;

    /**
     * Start a request transaction.
     */
    public function startRequest(string $method, string $url, ?string $route = null): Span
    {
        $this->traceContext = TraceContext::generate();
        $this->currentSpan = new Span(
            $route ?? $url,
            $this->traceContext,
            null,
            'http.server'
        );

        $this->currentSpan->setTag('http.method', $method);
        $this->currentSpan->setTag('http.url', $url);

        if ($route !== null) {
            $this->currentSpan->setTag('http.route', $route);
        }

        return $this->currentSpan;
    }

    /**
     * Start a new span.
     */
    public function startSpan(string $name, string $op = 'default'): Span
    {
        if ($this->currentSpan === null) {
            // No active transaction, create standalone span
            $context = $this->traceContext ?? TraceContext::generate();
            $span = new Span($name, $context->createChild(), null, $op);
        } else {
            // Create child span
            $span = $this->currentSpan->startChild($name, $op);
        }

        $this->spans[] = $span;

        return $span;
    }

    /**
     * Get the current span.
     */
    public function getCurrentSpan(): ?Span
    {
        return $this->currentSpan;
    }

    /**
     * Finish a span.
     */
    public function finishSpan(Span $span): array
    {
        $span->finish();

        return $span->toArray();
    }

    /**
     * Finish the request and get the transaction data.
     */
    public function finishRequest(): array
    {
        if ($this->currentSpan === null) {
            return [];
        }

        // Finish any unfinished child spans
        foreach ($this->spans as $span) {
            if (!$span->isFinished()) {
                $span->finish();
            }
        }

        $this->currentSpan->finish();
        $data = $this->currentSpan->toArray();

        $this->currentSpan = null;
        $this->spans = [];

        return $data;
    }

    /**
     * Get the trace context.
     */
    public function getTraceContext(): ?TraceContext
    {
        return $this->traceContext;
    }

    /**
     * Set the trace context (e.g., from incoming request headers).
     */
    public function setTraceContext(TraceContext $context): self
    {
        $this->traceContext = $context;

        return $this;
    }

    /**
     * Check if there's an active transaction.
     */
    public function hasActiveTransaction(): bool
    {
        return $this->currentSpan !== null;
    }

    /**
     * Add a database query span.
     */
    public function addQuerySpan(string $sql, float $durationMs, string $connection = 'default'): Span
    {
        $span = $this->startSpan("Query: {$connection}", 'db.sql.query');

        $span->setData('sql', $sql);
        $span->setData('connection', $connection);
        $span->setTag('db.system', 'sql');
        $span->setTag('db.connection', $connection);

        // Finish immediately since we have the duration
        $span->finish();

        return $span;
    }

    /**
     * Add an HTTP client span.
     */
    public function addHttpClientSpan(
        string $method,
        string $url,
        int $statusCode,
        float $durationMs = 0
    ): Span {
        $span = $this->startSpan("HTTP {$method} {$url}", 'http.client');

        $span->setTag('http.method', $method);
        $span->setTag('http.url', $url);
        $span->setTag('http.status_code', $statusCode);
        $span->setData('duration_ms', $durationMs);

        if ($statusCode >= 400) {
            $span->setError("HTTP {$statusCode}");
        } else {
            $span->setOk();
        }

        $span->finish();

        return $span;
    }
}
