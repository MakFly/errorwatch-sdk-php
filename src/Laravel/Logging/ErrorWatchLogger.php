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
                    try {
                        $profile->refresh(request());
                    } catch (\Throwable) {
                    }
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

        $statusCode = $this->resolveHttpStatusCode($logContext, $requestContext['status_code'], $message);
        if ($statusCode !== null) {
            $logContext['status_code'] = $statusCode;
        }

        $url = $context['url'] ?? $requestContext['url'] ?? null;
        $source = $context['source'] ?? $this->inferLogSource($logContext, $message, $statusCode, $url);

        $logEntry = [
            'level' => $level,
            'message' => $message,
            'timestamp' => microtime(true),
            'channel' => $context['channel'] ?? 'application',
            'url' => $url,
            'context' => (object) $logContext,
            'extra' => (object) ($context['extra'] ?? []),
        ];

        if ($statusCode !== null) {
            $logEntry['status_code'] = $statusCode;
        }

        if ($source !== null) {
            $logEntry['source'] = $source;
        }

        $transaction = $this->client->getCurrentTransaction();
        if ($transaction !== null) {
            $logEntry['trace_id'] = $transaction->getTraceId();
            $logEntry['span_id'] = $transaction->getSpanId();
        }

        $this->client->deliverLog($logEntry);
    }

    /**
     * Resolve HTTP status for a live log: Monolog context first, then the SDK
     * request scope (post-response middleware snapshot), then a message fallback
     * for legacy request/response log formatters that embed the code in text.
     *
     * @param array<string, mixed> $logContext
     */
    protected function resolveHttpStatusCode(array $logContext, ?int $scopeStatusCode, string $message): ?int
    {
        $fromContext = $this->resolveStatusCodeFromContext($logContext);
        if ($fromContext !== null) {
            return $fromContext;
        }

        if ($scopeStatusCode !== null) {
            return $scopeStatusCode;
        }

        return $this->extractHttpStatusFromMessage($message);
    }

    /**
     * @param array<string, mixed> $logContext
     */
    protected function inferLogSource(array $logContext, string $message, ?int $statusCode, ?string $url): ?string
    {
        $kind = $logContext['log_kind'] ?? null;
        if (in_array($kind, ['http_request', 'http_response'], true)) {
            return 'http';
        }

        if (str_contains($message, '[RESPONSE]') || str_contains($message, '[REQUEST]')) {
            return 'http';
        }

        if ($statusCode !== null && $url !== null) {
            return 'http';
        }

        return null;
    }

    protected function coerceHttpStatusCode(mixed $value): ?int
    {
        if (is_int($value)) {
            return ($value >= 100 && $value <= 599) ? $value : null;
        }

        if (is_string($value) && preg_match('/^[0-9]{3}$/', trim($value))) {
            $code = (int) trim($value);
            return ($code >= 100 && $code <= 599) ? $code : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $logContext
     */
    protected function resolveStatusCodeFromContext(array $logContext): ?int
    {
        $statusPaths = [
            ['status_code'],
            ['statusCode'],
            ['status'],
            ['http_status_code'],
            ['response_status_code'],
            ['http.status_code'],
            ['response.status_code'],
            ['http', 'status_code'],
            ['response', 'status_code'],
            ['tags', 'status_code'],
            ['tags', 'statusCode'],
            ['tags', 'http.status_code'],
            ['tags', 'response.status_code'],
            ['tags', 'http_status_code'],
            ['tags', 'response_status_code'],
        ];

        foreach ($statusPaths as $path) {
            $value = $this->valueAtPath($logContext, $path);
            $statusCode = $this->coerceHttpStatusCode($value);
            if ($statusCode !== null) {
                return $statusCode;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $subject
     * @param array<int, string> $path
     */
    protected function valueAtPath(array $subject, array $path): mixed
    {
        $current = $subject;

        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    protected function extractHttpStatusFromMessage(string $message): ?int
    {
        if (preg_match('/Status Code\s*:\s*(?<code>[0-9]{3})\b/i', $message, $matches)) {
            return $this->coerceHttpStatusCode($matches['code']);
        }

        if (preg_match('/\bHTTP\s+(?<code>[0-9]{3})\b/i', $message, $matches)) {
            return $this->coerceHttpStatusCode($matches['code']);
        }

        return null;
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
