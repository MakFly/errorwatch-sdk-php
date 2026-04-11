<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Breadcrumbs;

class BreadcrumbManager
{
    protected array $breadcrumbs = [];
    protected int $maxCount;

    public function __construct(int $maxCount = 100)
    {
        $this->maxCount = $maxCount;
    }

    /**
     * Add a generic breadcrumb.
     */
    public function add(
        string $message,
        string $type = 'default',
        string $category = 'default',
        array $data = []
    ): self {
        $breadcrumb = [
            'message' => $message,
            'type' => $type,
            'category' => $category,
            'timestamp' => microtime(true),
            'data' => $data,
        ];

        $this->breadcrumbs[] = $breadcrumb;

        // Keep only the last maxCount breadcrumbs
        if (count($this->breadcrumbs) > $this->maxCount) {
            array_shift($this->breadcrumbs);
        }

        return $this;
    }

    /**
     * Add an HTTP request breadcrumb.
     */
    public function addRequest(string $method, string $url, int $statusCode, array $data = []): self
    {
        return $this->add(
            "HTTP {$method} {$url}",
            'http',
            'request',
            array_merge([
                'method' => $method,
                'url' => $url,
                'status_code' => $statusCode,
            ], $data)
        );
    }

    /**
     * Add a database query breadcrumb.
     */
    public function addQuery(string $sql, float $durationMs, string $connection = 'default', array $bindings = []): self
    {
        return $this->add(
            "Query: {$sql}",
            'query',
            'database',
            [
                'sql' => $sql,
                'duration_ms' => $durationMs,
                'connection' => $connection,
                'bindings_count' => count($bindings),
            ]
        );
    }

    /**
     * Add an HTTP client request breadcrumb (outgoing).
     */
    public function addHttp(string $method, string $url, int $statusCode, float $durationMs = 0): self
    {
        return $this->add(
            "HTTP Client: {$method} {$url}",
            'http',
            'http_client',
            [
                'method' => $method,
                'url' => $url,
                'status_code' => $statusCode,
                'duration_ms' => $durationMs,
            ]
        );
    }

    /**
     * Add a console command breadcrumb.
     */
    public function addConsole(string $command, array $arguments = [], int $exitCode = 0): self
    {
        return $this->add(
            "Console: {$command}",
            'console',
            'command',
            [
                'command' => $command,
                'arguments' => $arguments,
                'exit_code' => $exitCode,
            ]
        );
    }

    /**
     * Add a security event breadcrumb.
     */
    public function addSecurity(string $event, array $data = []): self
    {
        return $this->add(
            "Security: {$event}",
            'security',
            'auth',
            array_merge(['event' => $event], $data)
        );
    }

    /**
     * Add a queue/job breadcrumb.
     */
    public function addQueue(string $jobName, string $queue, string $status, array $data = []): self
    {
        return $this->add(
            "Queue: {$jobName}",
            'queue',
            'job',
            array_merge([
                'job' => $jobName,
                'queue' => $queue,
                'status' => $status,
            ], $data)
        );
    }

    /**
     * Add a navigation breadcrumb.
     */
    public function addNavigation(string $from, string $to, array $data = []): self
    {
        return $this->add(
            "Navigate: {$from} -> {$to}",
            'navigation',
            'route',
            array_merge([
                'from' => $from,
                'to' => $to,
            ], $data)
        );
    }

    /**
     * Add a user action breadcrumb.
     */
    public function addUserAction(string $action, array $data = []): self
    {
        return $this->add(
            "User: {$action}",
            'user',
            'action',
            array_merge(['action' => $action], $data)
        );
    }

    /**
     * Get all breadcrumbs.
     */
    public function all(): array
    {
        return $this->breadcrumbs;
    }

    /**
     * Clear all breadcrumbs.
     */
    public function clear(): self
    {
        $this->breadcrumbs = [];

        return $this;
    }

    /**
     * Get the count of breadcrumbs.
     */
    public function count(): int
    {
        return count($this->breadcrumbs);
    }

    /**
     * Set the maximum number of breadcrumbs to keep.
     */
    public function setMaxCount(int $maxCount): self
    {
        $this->maxCount = $maxCount;

        // Trim if we have more than the new max
        if (count($this->breadcrumbs) > $this->maxCount) {
            $this->breadcrumbs = array_slice(
                $this->breadcrumbs,
                -$this->maxCount
            );
        }

        return $this;
    }
}
