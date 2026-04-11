<?php

namespace ErrorWatch\Symfony\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Internal logger for ErrorWatch SDK warnings.
 * Designed to never throw exceptions and gracefully degrade.
 */
final class ErrorWatchLogger
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Log a warning message with ErrorWatch prefix.
     * Catches all exceptions to ensure it never breaks the application.
     *
     * @param string $message Warning message (will be prefixed with [ErrorWatch])
     * @param array<string, mixed> $context Additional context
     */
    public function warning(string $message, array $context = []): void
    {
        try {
            $this->logger->warning('[ErrorWatch] '.$message, $context);
        } catch (\Throwable) {
            // Silently fail - never break the host application
        }
    }

    /**
     * Log a debug message with ErrorWatch prefix.
     * Catches all exceptions to ensure it never breaks the application.
     *
     * @param string $message Debug message (will be prefixed with [ErrorWatch])
     * @param array<string, mixed> $context Additional context
     */
    public function debug(string $message, array $context = []): void
    {
        try {
            $this->logger->debug('[ErrorWatch] '.$message, $context);
        } catch (\Throwable) {
            // Silently fail - never break the host application
        }
    }
}
