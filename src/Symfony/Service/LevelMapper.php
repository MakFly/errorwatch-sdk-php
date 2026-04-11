<?php

namespace ErrorWatch\Symfony\Service;

use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Maps exceptions and PSR-3 log levels to error monitoring levels.
 */
class LevelMapper
{
    public const LEVEL_FATAL = 'fatal';
    public const LEVEL_ERROR = 'error';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_INFO = 'info';
    public const LEVEL_DEBUG = 'debug';

    /**
     * Map exception to error level.
     */
    public function mapException(\Throwable $throwable): string
    {
        // HTTP Exceptions - map by status code
        if ($throwable instanceof HttpExceptionInterface) {
            $statusCode = $throwable->getStatusCode();

            if ($statusCode >= 500) {
                return self::LEVEL_FATAL;
            }
            // 404 Not Found is informational (expected condition)
            if (404 === $statusCode) {
                return self::LEVEL_INFO;
            }
            if ($statusCode >= 400) {
                return self::LEVEL_WARNING;
            }

            return self::LEVEL_INFO;
        }

        // Core PHP Errors (most severe)
        if ($throwable instanceof \Error) {
            if ($throwable instanceof \ParseError
                || $throwable instanceof \TypeError
                || $throwable instanceof \ArithmeticError
                || $throwable instanceof \DivisionByZeroError) {
                return self::LEVEL_FATAL;
            }

            return self::LEVEL_ERROR;
        }

        // Overflow/Underflow - severe
        if ($throwable instanceof \OverflowException
            || $throwable instanceof \UnderflowException) {
            return self::LEVEL_ERROR;
        }

        // Argument/Range exceptions (validation errors) - warnings
        if ($throwable instanceof \InvalidArgumentException
            || $throwable instanceof \RangeException
            || $throwable instanceof \OutOfRangeException
            || $throwable instanceof \OutOfBoundsException
            || $throwable instanceof \LengthException
            || $throwable instanceof \DomainException) {
            return self::LEVEL_WARNING;
        }

        // Runtime exceptions - general errors
        if ($throwable instanceof \RuntimeException) {
            return self::LEVEL_ERROR;
        }

        // Logic exceptions (programmer errors) - info
        if ($throwable instanceof \LogicException) {
            return self::LEVEL_INFO;
        }

        // Default to error
        return self::LEVEL_ERROR;
    }

    /**
     * Map PSR-3 log level to error level.
     */
    public function mapPsr3Level(string $psr3Level): string
    {
        return match (strtolower($psr3Level)) {
            LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL => self::LEVEL_FATAL,
            LogLevel::ERROR => self::LEVEL_ERROR,
            LogLevel::WARNING => self::LEVEL_WARNING,
            LogLevel::NOTICE, LogLevel::INFO => self::LEVEL_INFO,
            LogLevel::DEBUG => self::LEVEL_DEBUG,
            default => self::LEVEL_ERROR,
        };
    }

    /**
     * Get all valid levels.
     *
     * @return array<int, string>
     */
    public static function getValidLevels(): array
    {
        return [
            self::LEVEL_FATAL,
            self::LEVEL_ERROR,
            self::LEVEL_WARNING,
            self::LEVEL_INFO,
            self::LEVEL_DEBUG,
        ];
    }

    /**
     * Check if a level is valid.
     */
    public static function isValidLevel(string $level): bool
    {
        return in_array($level, self::getValidLevels(), true);
    }
}
