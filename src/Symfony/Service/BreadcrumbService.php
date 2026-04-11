<?php

namespace ErrorWatch\Symfony\Service;

use ErrorWatch\Symfony\Model\Breadcrumb;

/**
 * Service for managing breadcrumbs (user action trail leading to errors).
 *
 * Breadcrumbs are stored in memory and attached to error events when they occur.
 */
final class BreadcrumbService
{
    /** @var array<int, array<string, mixed>> */
    private array $breadcrumbs = [];

    private readonly int $maxBreadcrumbs;

    public function __construct(int $maxBreadcrumbs = 100)
    {
        $this->maxBreadcrumbs = $maxBreadcrumbs;
    }

    /**
     * Add a breadcrumb to the trail.
     */
    public function add(Breadcrumb $breadcrumb): void
    {
        $this->breadcrumbs[] = $breadcrumb->toArray();

        // Remove oldest breadcrumbs if we exceed the limit
        if (count($this->breadcrumbs) > $this->maxBreadcrumbs) {
            array_shift($this->breadcrumbs);
        }
    }

    /**
     * Add a raw breadcrumb array.
     *
     * @param array<string, mixed> $breadcrumb
     */
    public function addArray(array $breadcrumb): void
    {
        $this->breadcrumbs[] = $breadcrumb;

        if (count($this->breadcrumbs) > $this->maxBreadcrumbs) {
            array_shift($this->breadcrumbs);
        }
    }

    /**
     * Get all breadcrumbs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->breadcrumbs;
    }

    /**
     * Get breadcrumb count.
     */
    public function count(): int
    {
        return count($this->breadcrumbs);
    }

    /**
     * Clear all breadcrumbs.
     */
    public function clear(): void
    {
        $this->breadcrumbs = [];
    }

    /**
     * Check if breadcrumbs is empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->breadcrumbs);
    }
}
