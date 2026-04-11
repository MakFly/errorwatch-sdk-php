<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Exception;

class StacktraceBuilder
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly int    $contextLines = 5,
    ) {}

    /**
     * Build an array of Frame objects from a Throwable's stack trace.
     *
     * @return Frame[]
     */
    public function buildFromThrowable(\Throwable $e): array
    {
        $frames = [];

        // Add the origin frame (file/line where exception was thrown)
        $originFile = $e->getFile();
        $originLine = $e->getLine();

        $trace = $e->getTrace();

        // Build frames from trace (most recent call last — we reverse)
        foreach ($trace as $entry) {
            $filename = $entry['file'] ?? '[internal]';
            $lineno   = $entry['line'] ?? null;
            $function = $this->formatFunction($entry);

            [$contextLine, $preContext, $postContext] = $this->getSourceContext($filename, $lineno);

            $frames[] = new Frame(
                filename:    $filename,
                function:    $function,
                lineno:      $lineno,
                inApp:       $this->isInApp($filename),
                contextLine: $contextLine,
                preContext:  $preContext,
                postContext: $postContext,
            );
        }

        // Reverse so innermost frame is last (standard stacktrace order)
        $frames = array_reverse($frames);

        // Append the origin as the innermost frame
        [$contextLine, $preContext, $postContext] = $this->getSourceContext($originFile, $originLine);

        $frames[] = new Frame(
            filename:    $originFile,
            function:    null,
            lineno:      $originLine,
            inApp:       $this->isInApp($originFile),
            contextLine: $contextLine,
            preContext:  $preContext,
            postContext: $postContext,
        );

        return $frames;
    }

    /**
     * Determine if a frame belongs to the application (not a vendor/internal frame).
     */
    private function isInApp(string $filename): bool
    {
        if ($filename === '' || $filename === '[internal]') {
            return false;
        }

        if (str_contains($filename, '/vendor/')) {
            return false;
        }

        $root = rtrim($this->projectRoot, '/');
        if ($root !== '' && !str_starts_with($filename, $root)) {
            return false;
        }

        return true;
    }

    /**
     * Read source lines around a given line number.
     *
     * @return array{?string, ?array, ?array}  [contextLine, preContext, postContext]
     */
    private function getSourceContext(string $filename, ?int $lineno): array
    {
        if ($lineno === null || $filename === '' || $filename === '[internal]') {
            return [null, null, null];
        }

        try {
            if (!is_readable($filename)) {
                return [null, null, null];
            }

            $lines = @file($filename, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                return [null, null, null];
            }

            $index = $lineno - 1; // 0-based
            if ($index < 0 || $index >= count($lines)) {
                return [null, null, null];
            }

            $contextLine = $lines[$index];

            $preStart  = max(0, $index - $this->contextLines);
            $postEnd   = min(count($lines) - 1, $index + $this->contextLines);

            $preContext  = $index > 0
                ? array_slice($lines, $preStart, $index - $preStart)
                : null;
            $postContext = $index < count($lines) - 1
                ? array_slice($lines, $index + 1, $postEnd - $index)
                : null;

            return [
                $contextLine,
                $preContext ?: null,
                $postContext ?: null,
            ];
        } catch (\Throwable) {
            return [null, null, null];
        }
    }

    private function formatFunction(array $entry): ?string
    {
        if (!isset($entry['function'])) {
            return null;
        }

        $fn = $entry['function'];

        if (isset($entry['class'])) {
            $type = $entry['type'] ?? '::';
            return $entry['class'] . $type . $fn;
        }

        return $fn;
    }
}
