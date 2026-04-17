<?php

namespace ErrorWatch\Symfony\Model;

final class Transaction
{
    private const MAX_SPANS = 200;

    private string $id;
    private string $name;
    private string $op;
    private string $status = 'ok';
    private int $startTimestamp;
    private ?int $endTimestamp = null;
    private ?string $traceId = null;
    private ?string $parentSpanId = null;
    /** @var Span[] */
    private array $spans = [];
    /** @var array<string, string> */
    private array $tags = [];
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(string $name, string $op = 'http.server')
    {
        $this->id = bin2hex(random_bytes(16));
        $this->name = $name;
        $this->op = $op;
        $this->startTimestamp = (int) (microtime(true) * 1000);
    }

    public function setTraceId(?string $traceId): void
    {
        $this->traceId = $traceId;
    }

    public function setParentSpanId(?string $parentSpanId): void
    {
        $this->parentSpanId = $parentSpanId;
    }

    public function getTraceId(): ?string
    {
        return $this->traceId;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function finish(): void
    {
        $this->endTimestamp = (int) (microtime(true) * 1000);
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function addSpan(Span $span): void
    {
        if (count($this->spans) >= self::MAX_SPANS) {
            return;
        }
        $this->spans[] = $span;
    }

    public function setTag(string $key, string $value): void
    {
        $this->tags[$key] = $value;
    }

    public function setData(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    /**
     * @return Span[]
     */
    public function getSpans(): array
    {
        return $this->spans;
    }

    public function getDurationMs(): int
    {
        if (null === $this->endTimestamp) {
            return (int) (microtime(true) * 1000) - $this->startTimestamp;
        }

        return $this->endTimestamp - $this->startTimestamp;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'op' => $this->op,
            'status' => $this->status,
            'startTimestamp' => $this->startTimestamp,
            'endTimestamp' => $this->endTimestamp,
            'duration' => $this->getDurationMs(),
            'traceId' => $this->traceId,
            'parentSpanId' => $this->parentSpanId,
            'spans' => array_map(fn (Span $span) => $span->toArray(), $this->spans),
            'tags' => $this->tags,
            'data' => $this->data,
        ];
    }
}
