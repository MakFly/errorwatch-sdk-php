<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Logging;

use ErrorWatch\Laravel\Client\MonitoringClient;
use ErrorWatch\Laravel\Profiler\RequestProfile;
use ErrorWatch\Sdk\Exception\StacktraceBuilder;
use Throwable;

class ErrorWatchLogger
{
    /**
     * Hard cap the backend enforces on a log/event `message` (20000 chars).
     */
    private const MAX_MESSAGE_LENGTH = 18000;

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

    /**
     * Sentry Monolog parity:
     * - info/warning → breadcrumbs
     * - warning+ → live logs
     * - error+ issues only when capture_as_events is enabled
     * - skip when context carries a Throwable (already captured)
     */
    public function handleLog(string $level, string $message, array $context = []): void
    {
        if (!$this->client->isEnabled()) {
            return;
        }

        if (!$this->client->getConfig('logging.enabled', true)) {
            return;
        }

        $innerContext = $context['context'] ?? [];
        if (($innerContext['exception'] ?? null) instanceof Throwable) {
            return;
        }

        $minLevel = $this->client->getConfig('logging.level', 'error');
        if (!$this->shouldLog($level, $minLevel)) {
            return;
        }

        $message = $this->truncateMessage($message);
        $channel = $context['channel'] ?? 'application';

        $breadcrumbLevel = $this->client->getConfig('logging.breadcrumb_level', 'info');
        if ($this->shouldLog($level, $breadcrumbLevel)) {
            $this->client->addBreadcrumb($message, 'log', [
                'channel' => $channel,
                'level' => $level,
            ]);
        }

        $logsLevel = $this->client->getConfig('logging.logs_level', 'warning');
        if ($this->shouldLog($level, $logsLevel)) {
            $this->sendLiveLog($level, $message, $context);
        }

        $captureAsEvents = (bool) $this->client->getConfig('logging.capture_as_events', false);
        $captureLevel = $this->client->getConfig('logging.capture_as_events_level', 'fatal');
        if (!$captureAsEvents || !$this->shouldLog($level, $captureLevel)) {
            return;
        }

        $formattedContext = $this->buildEventContext($message, $context);
        $this->client->captureMessage($message, $level, $formattedContext);
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

    protected function truncateMessage(string $message): string
    {
        if (mb_strlen($message) <= self::MAX_MESSAGE_LENGTH) {
            return $message;
        }

        $dropped = mb_strlen($message) - self::MAX_MESSAGE_LENGTH;

        return mb_substr($message, 0, self::MAX_MESSAGE_LENGTH) . " …[truncated {$dropped} chars]";
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildEventContext(string $message, array $context): array
    {
        $formattedContext = [
            'context' => $context['context'] ?? [],
            'extra' => $context['extra'] ?? [],
        ];

        if (!empty($context)) {
            $formattedContext['extra']['laravel_log_context'] = $context;
        }

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
        }

        try {
            $frames = $this->captureBacktraceFrames();
            if (!empty($frames)) {
                $formattedContext['frames'] = $frames;
            }
            [$file, $line] = $this->extractFileLineFromMessage($message);
            if ($file !== null) {
                $formattedContext['extra']['origin_file'] = $file;
                $formattedContext['extra']['origin_line'] = $line;
            }
        } catch (\Throwable) {
        }

        return $formattedContext;
    }

    protected function sendLiveLog(string $level, string $message, array $context): void
    {
        $excludedChannels = $this->client->getConfig('logs.excluded_channels', []);

        if (isset($context['channel']) && in_array($context['channel'], $excludedChannels, true)) {
            return;
        }

        $requestContext = $this->client->getRequestContext();
        $logContext = $context['context'] ?? [];
        if (!isset($logContext['status_code']) && $requestContext['status_code'] !== null) {
            $logContext['status_code'] = $requestContext['status_code'];
        }

        $statusCode = $logContext['status_code'] ?? null;

        $logEntry = [
            'level' => $level,
            'message' => $message,
            'timestamp' => microtime(true),
            'channel' => $context['channel'] ?? 'application',
            'url' => $context['url'] ?? $requestContext['url'],
            'context' => (object) $logContext,
            'extra' => (object) ($context['extra'] ?? []),
        ];

        if ($statusCode !== null) {
            $logEntry['status_code'] = $statusCode;
        }

        $this->client->deliverLog($logEntry);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function captureBacktraceFrames(int $maxFrames = 30): array
    {
        $root = $this->client->getConfig('project_root');
        if (!$root && function_exists('base_path')) {
            $root = base_path();
        }
        if (!$root) {
            $root = getcwd() ?: '';
        }
        $projectRoot = (string) $root;
        $stackBuilder = new StacktraceBuilder($projectRoot);

        $raw = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $maxFrames + 10);
        $frames = [];

        foreach ($raw as $f) {
            $file = $f['file'] ?? null;
            if ($file === null) {
                continue;
            }
            if (str_contains($file, 'vendor/errorwatch/sdk-php/')) {
                continue;
            }
            if (preg_match('#vendor/laravel/framework/src/Illuminate/(Log|Events)/#', $file)) {
                continue;
            }
            $func = $f['function'] ?? null;
            if (isset($f['class'])) {
                $func = $f['class'] . ($f['type'] ?? '::') . $func;
            }

            $lineno = $f['line'] ?? null;
            [$contextLine, $preContext, $postContext] = $stackBuilder->sourceContextAt($file, $lineno);

            $inApp = !str_contains($file, '/vendor/')
                && !preg_match('#/(Tilvest/Logger/|IapiLogger\.php|ApiLogger\.php)#', $file);

            $frame = [
                'filename' => $file,
                'function' => $func,
                'lineno' => $lineno,
                'in_app' => $inApp,
            ];

            if ($contextLine !== null) {
                $frame['context_line'] = $contextLine;
            }
            if ($preContext !== null) {
                $frame['pre_context'] = $preContext;
            }
            if ($postContext !== null) {
                $frame['post_context'] = $postContext;
            }

            $frames[] = $frame;

            if (count($frames) >= $maxFrames) {
                break;
            }
        }

        return array_reverse($frames);
    }

    /**
     * @return array{0: string|null, 1: int|null}
     */
    protected function extractFileLineFromMessage(string $message): array
    {
        if (preg_match('# in (?<file>[^\s]+\.\w+) on line (?<line>\d+)\b#', $message, $m)) {
            return [$m['file'], (int) $m['line']];
        }
        return [null, null];
    }
}
