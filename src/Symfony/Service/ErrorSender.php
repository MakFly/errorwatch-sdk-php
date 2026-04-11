<?php

namespace ErrorWatch\Symfony\Service;

use ErrorWatch\Symfony\Http\MonitoringClientInterface;

class ErrorSender implements ErrorSenderInterface
{
    private bool $enabled;
    private ?string $environment;
    private ?string $release;
    private MonitoringClientInterface $client;
    private LevelMapper $levelMapper;

    public function __construct(
        bool $enabled,
        ?string $environment,
        ?string $release,
        MonitoringClientInterface $client,
        LevelMapper $levelMapper,
    ) {
        $this->enabled = $enabled;
        $this->environment = $environment;
        $this->release = $release;
        $this->client = $client;
        $this->levelMapper = $levelMapper;
    }

    public function send(
        \Throwable $throwable,
        ?string $url = null,
        ?string $level = null,
        ?string $sessionId = null,
        array $context = [],
    ): void {
        if (!$this->enabled) {
            return;
        }

        $payload = $this->buildPayload($throwable, $url, $level, $sessionId, $context);
        $this->client->sendEventAsync($payload);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function buildPayload(
        \Throwable $throwable,
        ?string $url,
        ?string $level,
        ?string $sessionId,
        array $context = [],
    ): array {
        $trace = $throwable->getTraceAsString();
        $file = $throwable->getFile();
        $line = $throwable->getLine();

        // Auto-detect level from exception if not provided
        $resolvedLevel = $level;
        if (null === $resolvedLevel || !LevelMapper::isValidLevel($resolvedLevel)) {
            $resolvedLevel = $this->levelMapper->mapException($throwable);
        }

        $payload = [
            'message' => $throwable->getMessage(),
            'file' => $file,
            'line' => $line,
            'stack' => $trace,
            'env' => $this->environment,
            'url' => $url,
            'level' => $resolvedLevel,
            'release' => $this->release,
            'created_at' => (int) (microtime(true) * 1000),
        ];

        // Only include status_code if it's an HTTP exception
        if ($throwable instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            $payload['status_code'] = $throwable->getStatusCode();
        }

        // Include session_id for replay linking (only for critical errors)
        $criticalLevels = [LevelMapper::LEVEL_FATAL, LevelMapper::LEVEL_ERROR];
        if (null !== $sessionId && in_array($resolvedLevel, $criticalLevels, true)) {
            $payload['session_id'] = $sessionId;
        }

        // Add breadcrumbs from context
        if (!empty($context['breadcrumbs'])) {
            $payload['breadcrumbs'] = $context['breadcrumbs'];
        }

        // Add user context
        if (!empty($context['user'])) {
            $payload['user'] = $context['user'];
        }

        // Add request context
        if (!empty($context['request'])) {
            $payload['request'] = $context['request'];
        }

        return $payload;
    }
}
