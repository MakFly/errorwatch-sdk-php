<?php

namespace ErrorWatch\Symfony\Model;

final class Span
{
    private string $id;
    private string $op;
    private ?string $description;
    private string $status = 'ok';
    private int $startTimestamp;
    private ?int $endTimestamp = null;
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(string $op, ?string $description = null)
    {
        $this->id = bin2hex(random_bytes(16));
        $this->op = $op;
        $this->description = $description;
        $this->startTimestamp = (int) (microtime(true) * 1000);
    }

    public function finish(): void
    {
        $this->endTimestamp = (int) (microtime(true) * 1000);
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function setData(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function getOp(): string
    {
        return $this->op;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getDurationMs(): float
    {
        if (null === $this->endTimestamp) {
            return (float) ((int) (microtime(true) * 1000) - $this->startTimestamp);
        }

        return (float) ($this->endTimestamp - $this->startTimestamp);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'op' => $this->op,
            'description' => $this->description,
            'status' => $this->status,
            'startTimestamp' => $this->startTimestamp,
            'endTimestamp' => $this->endTimestamp,
            'data' => $this->data,
        ];
    }
}
