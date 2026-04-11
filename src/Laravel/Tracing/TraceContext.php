<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Tracing;

class TraceContext
{
    protected string $traceId;
    protected string $spanId;
    protected ?string $parentSpanId;

    public function __construct(
        string $traceId,
        string $spanId,
        ?string $parentSpanId = null
    ) {
        $this->traceId = $traceId;
        $this->spanId = $spanId;
        $this->parentSpanId = $parentSpanId;
    }

    /**
     * Generate a new trace context with random IDs.
     */
    public static function generate(): self
    {
        return new self(
            self::generateTraceId(),
            self::generateSpanId()
        );
    }

    /**
     * Generate a trace ID (32 hex characters).
     */
    public static function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Generate a span ID (16 hex characters).
     */
    public static function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Create a child context from this one.
     */
    public function createChild(): self
    {
        return new self(
            $this->traceId,
            self::generateSpanId(),
            $this->spanId
        );
    }

    /**
     * Get the trace ID.
     */
    public function getTraceId(): string
    {
        return $this->traceId;
    }

    /**
     * Get the span ID.
     */
    public function getSpanId(): string
    {
        return $this->spanId;
    }

    /**
     * Get the parent span ID.
     */
    public function getParentSpanId(): ?string
    {
        return $this->parentSpanId;
    }

    /**
     * Set the parent span ID.
     */
    public function setParentSpanId(string $parentSpanId): self
    {
        $this->parentSpanId = $parentSpanId;

        return $this;
    }

    /**
     * Convert to array format.
     */
    public function toArray(): array
    {
        return [
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId,
            'parent_span_id' => $this->parentSpanId,
        ];
    }

    /**
     * Convert to W3C Trace Context header format.
     */
    public function toTraceParentHeader(): string
    {
        return sprintf(
            '00-%s-%s-01',
            $this->traceId,
            $this->spanId
        );
    }

    /**
     * Parse from W3C Trace Context header.
     */
    public static function fromTraceParentHeader(string $header): ?self
    {
        // Format: 00-{trace-id}-{parent-id}-{flags}
        if (!preg_match('/^00-([a-f0-9]{32})-([a-f0-9]{16})-([a-f0-9]{2})$/', $header, $matches)) {
            return null;
        }

        return new self(
            $matches[1],
            self::generateSpanId(),
            $matches[2]
        );
    }

    /**
     * Convert to JSON-serializable format.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
