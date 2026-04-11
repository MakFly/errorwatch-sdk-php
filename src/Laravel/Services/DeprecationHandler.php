<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Services;

use ErrorWatch\Laravel\Client\MonitoringClient;

/**
 * Handles PHP deprecation warnings and sends them to ErrorWatch.
 *
 * Captures E_DEPRECATED and E_USER_DEPRECATED errors, deduplicates them,
 * and sends them as warning-level events to the monitoring server.
 */
final class DeprecationHandler
{
    /** @var array<string, true> Cache of seen deprecations for deduplication */
    private array $seen = [];

    private bool $enabled = true;

    public function __construct(
        private readonly MonitoringClient $client
    ) {
        // Register error handler for deprecation types only
        $previousHandler = set_error_handler(
            [$this, 'handleDeprecation'],
            \E_DEPRECATED | \E_USER_DEPRECATED
        );

        // Preserve previous handler if any
        if ($previousHandler !== null) {
            restore_error_handler();
            $this->enabled = false;
        }
    }

    /**
     * Handle a deprecation warning.
     *
     * @param int $errno Error type (E_DEPRECATED or E_USER_DEPRECATED)
     * @param string $message Deprecation message
     * @param string $file File where deprecation occurred
     * @param int $line Line number
     * @return bool False to allow standard PHP error handling to continue
     */
    public function handleDeprecation(int $errno, string $message, string $file, int $line): bool
    {
        if (!$this->enabled) {
            return false;
        }

        // Deduplicate by hash to avoid flooding
        $key = md5($message . $file . $line);

        if (isset($this->seen[$key])) {
            return false; // Already sent, don't spam
        }

        $this->seen[$key] = true;

        // Capture backtrace for context
        $backtrace = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $stack = $this->formatBacktrace($backtrace);

        // Send as warning-level event
        $this->client->captureEvent([
            'message' => '[Deprecation] ' . $message,
            'file' => $file,
            'line' => $line,
            'stack' => $stack,
            'level' => 'warning',
            'url' => sprintf('deprecation://%s:%d', $file, $line),
        ]);

        // Return false to NOT suppress standard PHP error handling
        // This allows deprecations to still appear in logs if configured
        return false;
    }

    /**
     * Format a backtrace into a readable string.
     *
     * @param array<int, array<string, mixed>> $backtrace
     */
    private function formatBacktrace(array $backtrace): string
    {
        $lines = [];

        foreach ($backtrace as $i => $frame) {
            $file = $frame['file'] ?? 'unknown';
            $line = $frame['line'] ?? 0;
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';
            $function = $frame['function'] ?? '';

            $lines[] = sprintf(
                '#%d %s(%d): %s%s%s()',
                $i,
                $file,
                $line,
                $class,
                $type,
                $function
            );
        }

        return implode("\n", $lines);
    }
}
