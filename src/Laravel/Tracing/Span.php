<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Tracing;

class Span
{
    protected string $name;
    protected string $op;
    protected TraceContext $context;
    protected ?Span $parent;
    protected float $startTimestamp;
    protected ?float $endTimestamp = null;
    protected array $tags = [];
    protected array $data = [];
    protected ?string $status = null;
    protected ?string $statusDescription = null;
    protected array $spans = [];

    public function __construct(
        string $name,
        TraceContext $context,
        ?Span $parent = null,
        string $op = 'default'
    ) {
        $this->name = $name;
        $this->op = $op;
        $this->context = $context;
        $this->parent = $parent;
        $this->startTimestamp = microtime(true) * 1000;
    }

    /**
     * Set the operation name.
     */
    public function setOp(string $op): self
    {
        $this->op = $op;

        return $this;
    }

    /**
     * Get the operation name.
     */
    public function getOp(): string
    {
        return $this->op;
    }

    /**
     * Set a tag.
     */
    public function setTag(string $key, mixed $value): self
    {
        $this->tags[$key] = $value;

        return $this;
    }

    /**
     * Set multiple tags.
     */
    public function setTags(array $tags): self
    {
        foreach ($tags as $key => $value) {
            $this->setTag($key, $value);
        }

        return $this;
    }

    /**
     * Get a tag.
     */
    public function getTag(string $key): mixed
    {
        return $this->tags[$key] ?? null;
    }

    /**
     * Get all tags.
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * Set data (extra context).
     */
    public function setData(string $key, mixed $value): self
    {
        $this->data[$key] = $value;

        return $this;
    }

    /**
     * Set multiple data entries.
     */
    public function setMultipleData(array $data): self
    {
        foreach ($data as $key => $value) {
            $this->setData($key, $value);
        }

        return $this;
    }

    /**
     * Get all data.
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Set the span status.
     */
    public function setStatus(string $status, ?string $description = null): self
    {
        $this->status = $status;
        $this->statusDescription = $description;

        return $this;
    }

    /**
     * Get the status.
     */
    public function getStatus(): ?string
    {
        return $this->status;
    }

    /**
     * Mark the span as OK.
     */
    public function setOk(): self
    {
        return $this->setStatus('ok');
    }

    /**
     * Mark the span as errored.
     */
    public function setError(?string $description = null): self
    {
        return $this->setStatus('error', $description);
    }

    /**
     * Override the start timestamp (e.g. to use LARAVEL_START for full request timing).
     */
    public function overrideStartTimestamp(float $timestampMs): void
    {
        $this->startTimestamp = $timestampMs;
    }

    /**
     * Finish the span.
     */
    public function finish(): void
    {
        if ($this->endTimestamp === null) {
            $this->endTimestamp = microtime(true) * 1000;
        }
    }

    /**
     * Finish the span with a known duration (e.g. from Laravel's QueryExecuted->time).
     */
    public function finishWithDuration(float $durationMs): void
    {
        $this->endTimestamp = $this->startTimestamp + $durationMs;
    }

    /**
     * Check if the span is finished.
     */
    public function isFinished(): bool
    {
        return $this->endTimestamp !== null;
    }

    /**
     * Get the duration in milliseconds.
     */
    public function getDurationMs(): float
    {
        $end = $this->endTimestamp ?? (microtime(true) * 1000);

        return $end - $this->startTimestamp;
    }

    /**
     * Get the start timestamp.
     */
    public function getStartTimestamp(): float
    {
        return $this->startTimestamp;
    }

    /**
     * Get the end timestamp.
     */
    public function getEndTimestamp(): ?float
    {
        return $this->endTimestamp;
    }

    /**
     * Get the trace context.
     */
    public function getContext(): TraceContext
    {
        return $this->context;
    }

    /**
     * Get the trace ID.
     */
    public function getTraceId(): string
    {
        return $this->context->getTraceId();
    }

    /**
     * Get the span ID.
     */
    public function getSpanId(): string
    {
        return $this->context->getSpanId();
    }

    /**
     * Start a child span.
     */
    public function startChild(string $name, string $op = 'default'): Span
    {
        $childContext = $this->context->createChild();
        $child = new Span($name, $childContext, $this, $op);
        $this->spans[] = $child;

        return $child;
    }

    /**
     * Get child spans.
     */
    public function getSpans(): array
    {
        return $this->spans;
    }

    /**
     * Convert to array format for the API.
     */
    public function toArray(): array
    {
        $result = [
            'id' => $this->context->getSpanId(),
            'name' => $this->name,
            'description' => $this->name,
            'op' => $this->op,
            'traceId' => $this->context->getTraceId(),
            'spanId' => $this->context->getSpanId(),
            'parentSpanId' => $this->context->getParentSpanId(),
            'startTimestamp' => $this->startTimestamp,
            'endTimestamp' => $this->endTimestamp,
            'durationMs' => $this->getDurationMs(),
            'tags' => $this->tags,
            'data' => $this->data,
        ];

        if ($this->status !== null) {
            $result['status'] = $this->status;
            if ($this->statusDescription !== null) {
                $result['statusDescription'] = $this->statusDescription;
            }
        }

        if (!empty($this->spans)) {
            $result['spans'] = array_map(
                fn(Span $span) => $span->toArray(),
                array_filter($this->spans, fn(Span $span) => $span->isFinished())
            );
        }

        return $result;
    }
}
