<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Logging;

use ErrorWatch\Laravel\Client\MonitoringClient;
use ErrorWatch\Laravel\Profiler\RequestProfile;
use Throwable;

class ErrorWatchLogger
{
    protected MonitoringClient $client;
    protected array $config;
    protected array $levelMap = [
        'debug' => 100,
        'info' => 200,
        'notice' => 250,
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
        'alert' => 550,
        'emergency' => 600,
    ];

    public function __construct(MonitoringClient $client, array $config)
    {
        $this->client = $client;
        $this->config = $config;
    }

    public function handleLog(string $level, string $message, array $context = []): void
    {
        if (!$this->client->isEnabled()) {
            return;
        }

        if (!$this->client->getConfig('logging.enabled', true)) {
            return;
        }

        $minLevel = $this->client->getConfig('logging.level', 'error');
        if (!$this->shouldLog($level, $minLevel)) {
            return;
        }

        $formattedContext = [
            'context' => $context['context'] ?? [],
            'extra' => $context['extra'] ?? [],
        ];

        if (!empty($context)) {
            $formattedContext['extra']['laravel_log_context'] = $context;
        }

        // Auto-attach the per-request profile when the profiler is on and a
        // RequestProfile bag has been started for the current HTTP request.
        // This makes Logger::warning / Logger::error events surface the same
        // Full Debug panel as captureException does.
        try {
            if (function_exists('app')
                && app('config')->get('errorwatch.profiler.enabled', true)
                && app()->bound(RequestProfile::class)) {
                $profile = app(RequestProfile::class);
                if ($profile->isStarted()) {
                    $formattedContext['profile'] = $profile->toArray();
                }
            }
        } catch (\Throwable) {
            // never let profiler attach crash the log path
        }

        $this->client->captureMessage($message, $level, $formattedContext);

        if ($this->client->getConfig('logs.enabled', true)) {
            $this->sendLiveLog($level, $message, $context);
        }
    }

    public function handleException(Throwable $e, array $context = []): ?string
    {
        if (!$this->client->isEnabled()) {
            return null;
        }

        if (!$this->client->getConfig('exceptions.enabled', true)) {
            return null;
        }

        return $this->client->captureException($e, $context);
    }

    protected function shouldLog(string $level, string $minLevel): bool
    {
        $levelValue = $this->levelMap[$level] ?? 400;
        $minLevelValue = $this->levelMap[$minLevel] ?? 400;

        return $levelValue >= $minLevelValue;
    }

    protected function sendLiveLog(string $level, string $message, array $context): void
    {
        $excludedChannels = $this->client->getConfig('logs.excluded_channels', []);

        if (isset($context['channel']) && in_array($context['channel'], $excludedChannels, true)) {
            return;
        }

        $logEntry = [
            'level' => $level,
            'message' => $message,
            'timestamp' => microtime(true),
            'channel' => $context['channel'] ?? 'application',
            'context' => (object) ($context['context'] ?? []),
            'extra' => (object) ($context['extra'] ?? []),
        ];

        $this->client->getTransport()->sendLog($logEntry);
    }
}
