<?php

namespace ErrorWatch\Symfony\Service;

interface ErrorSenderInterface
{
    /**
     * Send an error event to the monitoring server.
     *
     * @param \Throwable           $throwable The exception/error to report
     * @param string|null          $url       The URL where the error occurred
     * @param string|null          $level     Override the auto-detected level (fatal, error, warning, info)
     * @param string|null          $sessionId Session ID for replay linking
     * @param array<string, mixed> $context   Additional context (breadcrumbs, user, request)
     */
    public function send(
        \Throwable $throwable,
        ?string $url = null,
        ?string $level = null,
        ?string $sessionId = null,
        array $context = [],
    ): void;
}
