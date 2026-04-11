<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Services;

use Throwable;

class ExceptionCapturer
{
    /**
     * Format an exception for the API payload.
     */
    public static function format(Throwable $exception, ?int $maxFrames = null): array
    {
        return [
            'type' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'stacktrace' => static::formatStackTrace($exception->getTrace(), $maxFrames),
            'previous' => $exception->getPrevious() !== null
                ? static::format($exception->getPrevious(), $maxFrames)
                : null,
        ];
    }

    /**
     * Format a stack trace.
     */
    public static function formatStackTrace(array $trace, ?int $maxFrames = null): array
    {
        $frames = [];

        if ($maxFrames !== null) {
            $trace = array_slice($trace, 0, $maxFrames);
        }

        foreach ($trace as $frame) {
            $frames[] = [
                'file' => $frame['file'] ?? '[internal]',
                'line' => $frame['line'] ?? 0,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
                'type' => $frame['type'] ?? null,
                'args' => static::formatArgs($frame['args'] ?? []),
            ];
        }

        return $frames;
    }

    /**
     * Format function arguments.
     */
    protected static function formatArgs(array $args): array
    {
        $formatted = [];

        foreach ($args as $index => $arg) {
            $formatted[$index] = static::formatArg($arg);
        }

        return $formatted;
    }

    /**
     * Format a single argument.
     */
    protected static function formatArg(mixed $arg): string
    {
        if ($arg === null) {
            return 'null';
        }

        if (is_bool($arg)) {
            return $arg ? 'true' : 'false';
        }

        if (is_int($arg) || is_float($arg)) {
            return (string) $arg;
        }

        if (is_string($arg)) {
            // Truncate long strings
            if (strlen($arg) > 100) {
                return "'" . substr($arg, 0, 100) . "...' (truncated)";
            }

            return "'{$arg}'";
        }

        if (is_array($arg)) {
            return 'array(' . count($arg) . ')';
        }

        if (is_object($arg)) {
            return get_class($arg);
        }

        if (is_resource($arg)) {
            return 'resource';
        }

        return gettype($arg);
    }

    /**
     * Extract context from an exception.
     */
    public static function extractContext(Throwable $exception): array
    {
        $context = [];

        // Extract data from common exception types
        if (method_exists($exception, 'getContext')) {
            $context['exception_context'] = $exception->getContext();
        }

        if (method_exists($exception, 'errors')) {
            $context['validation_errors'] = $exception->errors();
        }

        return $context;
    }
}
