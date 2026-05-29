<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Logging;

use ErrorWatch\Laravel\Client\MonitoringClient;
use ErrorWatch\Laravel\Profiler\RequestProfile;
use Throwable;

class ErrorWatchLogger
{
    /**
     * Hard cap the backend enforces on a log/event `message` (20000 chars).
     * We truncate below it so a long message is delivered (clipped) rather
     * than silently rejected by the API's Zod schema.
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

        // Clip the message below the backend cap BEFORE both delivery paths
        // (captureMessage event path + sendLiveLog log path). Without this the
        // API silently drops the whole item when message > 20000 chars.
        $message = $this->truncateMessage($message);

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

        // Capture a structured stacktrace so log/warning events surface
        // file:line + call site in the dashboard, just like exceptions.
        // We also propagate a "throwable origin" if the deprecation message
        // ends with "in <file> on line <N>" (PHP error formatter pattern).
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
            // never let stacktrace capture crash the log path
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

    /**
     * Clip a message to MAX_MESSAGE_LENGTH, appending a marker noting how many
     * characters were dropped. Multibyte-safe so we never split a UTF-8 byte
     * sequence and produce invalid JSON. No-op for messages within the cap.
     */
    protected function truncateMessage(string $message): string
    {
        if (mb_strlen($message) <= self::MAX_MESSAGE_LENGTH) {
            return $message;
        }

        $dropped = mb_strlen($message) - self::MAX_MESSAGE_LENGTH;

        return mb_substr($message, 0, self::MAX_MESSAGE_LENGTH) . " …[truncated {$dropped} chars]";
    }

    protected function sendLiveLog(string $level, string $message, array $context): void
    {
        $excludedChannels = $this->client->getConfig('logs.excluded_channels', []);

        if (isset($context['channel']) && in_array($context['channel'], $excludedChannels, true)) {
            return;
        }

        // Pull the final request URL + HTTP status from the SDK scope (set by
        // ErrorWatchMiddleware on the post-response snapshot) so the backend can
        // filter logs by status_code, mirroring the event path. The final
        // status wins; an explicit caller-supplied value still takes priority.
        $requestContext = $this->client->getRequestContext();
        $logContext     = $context['context'] ?? [];
        if (!isset($logContext['status_code']) && $requestContext['status_code'] !== null) {
            $logContext['status_code'] = $requestContext['status_code'];
        }

        // Resolve the effective HTTP status once: an explicit caller value (now
        // merged into $logContext) wins, otherwise the request scope. We send it
        // as a top-level `status_code` (the backend `application_logs.status_code`
        // column + log ingest field) AND keep it in `context` for older backends.
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

        // Route via the client so `queue` mode dispatches a SendEventJob('log')
        // instead of issuing a synchronous HTTP call inside the web request.
        $this->client->deliverLog($logEntry);
    }

    /**
     * Capture a structured backtrace, skipping SDK + Laravel logging frames.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function captureBacktraceFrames(int $maxFrames = 30): array
    {
        $raw = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $maxFrames + 10);
        $frames = [];

        foreach ($raw as $f) {
            $file = $f['file'] ?? null;
            if ($file === null) {
                continue;
            }
            // Skip frames originating from the SDK itself
            if (str_contains($file, 'vendor/errorwatch/sdk-php/')) {
                continue;
            }
            // Skip Laravel logging plumbing frames
            if (preg_match('#vendor/laravel/framework/src/Illuminate/(Log|Events)/#', $file)) {
                continue;
            }

            $func = $f['function'] ?? null;
            if (isset($f['class'])) {
                $func = $f['class'] . ($f['type'] ?? '::') . $func;
            }

            $frames[] = [
                'filename' => $file,
                'function' => $func,
                'lineno' => $f['line'] ?? null,
                'in_app' => !str_contains($file, 'vendor/'),
            ];

            if (count($frames) >= $maxFrames) {
                break;
            }
        }

        // Sentry-style frames are oldest -> newest; debug_backtrace is the
        // opposite, so reverse to match what the dashboard expects.
        return array_reverse($frames);
    }

    /**
     * PHP error/deprecation messages end with "in <file> on line <N>".
     * Extract that pair so the dashboard can highlight the call site even
     * when the captured backtrace is shallow.
     *
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
