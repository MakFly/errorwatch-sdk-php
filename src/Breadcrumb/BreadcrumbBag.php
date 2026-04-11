<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Breadcrumb;

/**
 * Ring buffer collection of Breadcrumb instances.
 * When the max size is reached, the oldest entry is dropped.
 */
class BreadcrumbBag
{
    /** @var Breadcrumb[] */
    private array $items = [];

    public function __construct(
        private readonly int $maxSize = 100,
    ) {}

    public function add(Breadcrumb $breadcrumb): void
    {
        if ($this->maxSize <= 0) {
            return;
        }

        $this->items[] = $breadcrumb;

        if (count($this->items) > $this->maxSize) {
            array_shift($this->items);
        }
    }

    /**
     * @return Breadcrumb[]
     */
    public function all(): array
    {
        return $this->items;
    }

    public function toArray(): array
    {
        return array_map(static fn(Breadcrumb $b) => $b->toArray(), $this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function clear(): void
    {
        $this->items = [];
    }
}
