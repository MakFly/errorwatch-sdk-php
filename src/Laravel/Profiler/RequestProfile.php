<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Profiler;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Per-request profile snapshot (parity with laravel-web-profiler).
 *
 * The bag is a singleton in the container scoped to one HTTP request. Listeners
 * push records into it during the request, and the exception handler attaches
 * `toArray()` to the captured event payload as `profile`.
 *
 * All collector arrays are individually capped to keep the payload bounded.
 */
final class RequestProfile
{
    private const SENSITIVE_HEADERS = [
        'authorization',
        'cookie',
        'x-csrf-token',
        'x-xsrf-token',
        'php-auth-pw',
        'x-api-key',
    ];

    private const MAX_QUERIES = 200;
    private const MAX_CACHE_OPS = 500;
    private const MAX_HTTP_REQUESTS = 100;
    private const MAX_JOBS = 100;
    private const MAX_MAILS = 50;
    private const MAX_VIEWS = 100;
    private const MAX_GATES = 200;
    private const MAX_LOGS = 500;

    private string $token;
    private float $startedAt = 0.0;
    private bool $started = false;

    private ?array $request = null;
    private ?array $route = null;

    /** @var array<int, array<string, mixed>> */
    private array $queries = [];
    private float $queriesTotalMs = 0.0;
    private int $queriesSlow = 0;

    /** @var array<int, array<string, mixed>> */
    private array $cacheOps = [];
    private int $cacheHits = 0;
    private int $cacheMisses = 0;
    private int $cacheWrites = 0;
    private int $cacheDeletes = 0;

    /** @var array<int, array<string, mixed>> */
    private array $httpRequests = [];
    private float $httpTotalMs = 0.0;

    /** @var array<int, array<string, mixed>> */
    private array $jobs = [];
    private int $jobsFailed = 0;

    /** @var array<int, array<string, mixed>> */
    private array $mails = [];

    /** @var array<string, array{count: int, listeners: int, total_duration_ms: float}> */
    private array $events = [];

    /** @var array<int, array<string, mixed>> */
    private array $views = [];
    private float $viewsRenderMs = 0.0;

    /** @var array<int, array<string, mixed>> */
    private array $gates = [];
    private int $gatesAllowed = 0;
    private int $gatesDenied = 0;

    /** @var array<int, array<string, mixed>> */
    private array $logs = [];
    /** @var array<string, int> */
    private array $logsByLevel = [];

    public function __construct()
    {
        $this->token = bin2hex(random_bytes(16));
    }

    /**
     * Start a new profile for the incoming request. Resets all state.
     */
    public function start(Request $request): void
    {
        $this->reset();
        $this->started = true;
        $this->startedAt = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
        $this->request = $this->snapshotRequest($request);
        $this->route = $this->snapshotRoute($request);
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * Reset all per-request state. Called by start() and the Octane reset hook.
     */
    public function reset(): void
    {
        $this->token = bin2hex(random_bytes(16));
        $this->started = false;
        $this->startedAt = 0.0;
        $this->request = null;
        $this->route = null;
        $this->queries = [];
        $this->queriesTotalMs = 0.0;
        $this->queriesSlow = 0;
        $this->cacheOps = [];
        $this->cacheHits = 0;
        $this->cacheMisses = 0;
        $this->cacheWrites = 0;
        $this->cacheDeletes = 0;
        $this->httpRequests = [];
        $this->httpTotalMs = 0.0;
        $this->jobs = [];
        $this->jobsFailed = 0;
        $this->mails = [];
        $this->events = [];
        $this->views = [];
        $this->viewsRenderMs = 0.0;
        $this->gates = [];
        $this->gatesAllowed = 0;
        $this->gatesDenied = 0;
        $this->logs = [];
        $this->logsByLevel = [];
    }

    public function recordQuery(string $sql, array $bindings, float $timeMs, string $connection, int $slowThresholdMs = 100): void
    {
        if (!$this->started || count($this->queries) >= self::MAX_QUERIES) {
            return;
        }
        $isSlow = $timeMs > $slowThresholdMs;
        if ($isSlow) {
            $this->queriesSlow++;
        }
        $this->queriesTotalMs += $timeMs;
        $this->queries[] = [
            'sql' => $this->truncate($sql, 2000),
            'bindings' => array_slice($bindings, 0, 50),
            'bound_sql' => $this->bindSql($sql, $bindings),
            'time_ms' => round($timeMs, 3),
            'connection' => $connection,
            'is_slow' => $isSlow,
        ];
    }

    public function recordCacheOp(string $type, string $key, ?string $store): void
    {
        if (!$this->started) {
            return;
        }
        match ($type) {
            'hit' => $this->cacheHits++,
            'miss' => $this->cacheMisses++,
            'write' => $this->cacheWrites++,
            'delete' => $this->cacheDeletes++,
            default => null,
        };
        if (count($this->cacheOps) >= self::MAX_CACHE_OPS) {
            return;
        }
        $this->cacheOps[] = [
            'type' => $type,
            'key' => $this->truncate($key, 200),
            'store' => $store,
        ];
    }

    public function recordHttpRequest(string $method, string $url, int $statusCode, float $durationMs): void
    {
        if (!$this->started || count($this->httpRequests) >= self::MAX_HTTP_REQUESTS) {
            return;
        }
        $this->httpTotalMs += $durationMs;
        $this->httpRequests[] = [
            'method' => $method,
            'url' => $this->truncate($url, 1000),
            'status_code' => $statusCode,
            'duration_ms' => round($durationMs, 3),
            'request_headers' => [],
            'response_headers' => [],
        ];
    }

    public function recordJob(string $queue, string $class, string $status, float $durationMs): void
    {
        if (!$this->started || count($this->jobs) >= self::MAX_JOBS) {
            return;
        }
        if ($status === 'failed') {
            $this->jobsFailed++;
        }
        $this->jobs[] = [
            'queue' => $queue,
            'class' => $class,
            'status' => $status,
            'duration_ms' => round($durationMs, 3),
        ];
    }

    public function recordMail(array $message): void
    {
        if (!$this->started || count($this->mails) >= self::MAX_MAILS) {
            return;
        }
        $this->mails[] = [
            'to' => array_values((array) ($message['to'] ?? [])),
            'from' => array_values((array) ($message['from'] ?? [])),
            'cc' => array_values((array) ($message['cc'] ?? [])),
            'bcc' => array_values((array) ($message['bcc'] ?? [])),
            'subject' => (string) ($message['subject'] ?? ''),
            'body_excerpt' => isset($message['body']) ? mb_substr((string) $message['body'], 0, 500) : null,
            'attachments' => array_values((array) ($message['attachments'] ?? [])),
        ];
    }

    public function recordEvent(string $name, int $listenerCount, float $durationMs = 0.0): void
    {
        if (!$this->started) {
            return;
        }
        if (!isset($this->events[$name])) {
            $this->events[$name] = ['count' => 0, 'listeners' => $listenerCount, 'total_duration_ms' => 0.0];
        }
        $this->events[$name]['count']++;
        $this->events[$name]['total_duration_ms'] += $durationMs;
    }

    public function recordView(string $name, string $path, array $dataKeys = [], ?float $renderMs = null): void
    {
        if (!$this->started || count($this->views) >= self::MAX_VIEWS) {
            return;
        }
        if ($renderMs !== null) {
            $this->viewsRenderMs += $renderMs;
        }
        $this->views[] = [
            'name' => $name,
            'path' => $this->truncate($path, 1000),
            'data_keys' => array_slice(array_values($dataKeys), 0, 50),
            'render_time_ms' => $renderMs !== null ? round($renderMs, 3) : null,
        ];
    }

    public function recordGate(string $ability, bool $result, ?string $user, array $arguments = []): void
    {
        if (!$this->started || count($this->gates) >= self::MAX_GATES) {
            return;
        }
        $result ? $this->gatesAllowed++ : $this->gatesDenied++;
        $this->gates[] = [
            'ability' => $ability,
            'result' => $result,
            'user' => $user,
            'arguments_classes' => array_values(array_map(
                static fn ($arg) => is_object($arg) ? get_class($arg) : (is_scalar($arg) ? (string) $arg : gettype($arg)),
                $arguments,
            )),
        ];
    }

    public function recordLog(string $level, string $message, array $context = []): void
    {
        if (!$this->started || count($this->logs) >= self::MAX_LOGS) {
            return;
        }
        $normalized = strtolower($level);
        $this->logsByLevel[$normalized] = ($this->logsByLevel[$normalized] ?? 0) + 1;
        $this->logs[] = [
            'level' => $normalized,
            'message' => $this->truncate($message, 1000),
            'context' => $this->scrubContext($context),
            'time' => microtime(true),
        ];
    }

    /**
     * Build the final array payload. Called by the exception handler before
     * forwarding to captureException. Computes derived metrics and memory.
     */
    public function toArray(?Throwable $throwable = null, ?int $statusCode = null): array
    {
        $now = microtime(true);
        $durationMs = $this->startedAt > 0 ? ($now - $this->startedAt) * 1000.0 : 0.0;

        $resolvedStatus = $statusCode
            ?? ($throwable instanceof HttpExceptionInterface ? $throwable->getStatusCode() : 500);

        $totalCacheOps = $this->cacheHits + $this->cacheMisses + $this->cacheWrites + $this->cacheDeletes;
        $hitRatio = ($this->cacheHits + $this->cacheMisses) > 0
            ? round($this->cacheHits / ($this->cacheHits + $this->cacheMisses) * 100, 1)
            : 0.0;

        return [
            'token' => $this->token,
            'ip' => $this->request['ip'] ?? '',
            'method' => $this->request['method'] ?? '',
            'url' => $this->request['url'] ?? '',
            'status_code' => $resolvedStatus,
            'duration_ms' => round($durationMs, 3),
            'collected_at' => date('c'),

            'request' => $this->request,
            'route' => $this->route,

            'queries' => [
                'items' => $this->withDuplicates($this->queries),
                'total_count' => count($this->queries),
                'total_time_ms' => round($this->queriesTotalMs, 3),
                'slow_count' => $this->queriesSlow,
                'duplicate_count' => $this->countDuplicates($this->queries),
            ],

            'cache' => [
                'hits' => $this->cacheHits,
                'misses' => $this->cacheMisses,
                'writes' => $this->cacheWrites,
                'deletes' => $this->cacheDeletes,
                'hit_ratio' => $hitRatio,
                'operations' => $this->cacheOps,
                'total_count' => $totalCacheOps,
            ],

            'mail' => [
                'messages' => $this->mails,
                'total_count' => count($this->mails),
            ],

            'events' => [
                'byName' => $this->events,
                'total_count' => array_sum(array_column($this->events, 'count')),
                'unique_count' => count($this->events),
            ],

            'views' => [
                'items' => $this->views,
                'total_count' => count($this->views),
                'total_render_time_ms' => round($this->viewsRenderMs, 3),
            ],

            'gates' => [
                'checks' => $this->gates,
                'total_count' => count($this->gates),
                'allowed_count' => $this->gatesAllowed,
                'denied_count' => $this->gatesDenied,
            ],

            'http_client' => [
                'requests' => $this->httpRequests,
                'total_count' => count($this->httpRequests),
                'total_duration_ms' => round($this->httpTotalMs, 3),
            ],

            'logs' => [
                'items' => $this->logs,
                'counts_by_level' => $this->logsByLevel,
                'total_count' => count($this->logs),
                'highest_level' => $this->highestLogLevel(),
                'error_count' => ($this->logsByLevel['error'] ?? 0)
                    + ($this->logsByLevel['critical'] ?? 0)
                    + ($this->logsByLevel['alert'] ?? 0)
                    + ($this->logsByLevel['emergency'] ?? 0),
            ],

            'jobs' => [
                'items' => $this->jobs,
                'total_count' => count($this->jobs),
                'failed_count' => $this->jobsFailed,
            ],

            'memory' => [
                'peak_bytes' => memory_get_peak_usage(true),
                'limit_bytes' => $this->parseMemoryLimit((string) (ini_get('memory_limit') ?: '-1')),
                'opcache_mb' => $this->opcacheMb(),
                'usage_ratio' => $this->memoryRatio(),
            ],

            'timing' => [
                'duration_ms' => round($durationMs, 3),
                'events' => [],
            ],
        ];
    }

    private function snapshotRequest(Request $request): array
    {
        $session = null;
        try {
            if ($request->hasSession()) {
                $sess = $request->session();
                $session = ['id' => $sess->getId(), 'data' => $this->scrubContext($sess->all())];
            }
        } catch (\Throwable) {
            $session = null;
        }

        return [
            'ip' => $request->ip() ?? '',
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'path' => $request->path(),
            'query_string' => (string) ($request->getQueryString() ?? ''),
            'headers' => $this->filterHeaders($request->headers->all()),
            'content_type' => (string) $request->header('Content-Type', ''),
            'content_length' => (int) $request->header('Content-Length', 0),
            'cookies' => array_keys($request->cookies->all()),
            'session' => $session,
            'format' => $request->format(),
        ];
    }

    private function snapshotRoute(Request $request): ?array
    {
        $route = $request->route();
        if ($route === null) {
            return null;
        }

        try {
            $action = $route->getAction();
            return [
                'uri' => $route->uri(),
                'name' => $route->getName(),
                'action' => $action['uses'] ?? null,
                'controller' => $action['controller'] ?? null,
                'middleware' => array_values($route->gatherMiddleware()),
                'parameters' => $this->scrubContext($route->parameters()),
                'methods' => $route->methods(),
                'domain' => $route->getDomain(),
                'prefix' => $route->getPrefix(),
                'wheres' => (array) ($route->wheres ?? []),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, array<int, string>> $headers
     * @return array<string, array<int, string>>
     */
    private function filterHeaders(array $headers): array
    {
        return array_filter(
            $headers,
            static fn (string $name) => !in_array(strtolower($name), self::SENSITIVE_HEADERS, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Light scrubbing for arbitrary context arrays. Removes keys whose name
     * looks sensitive (password, secret, token, key, authorization).
     */
    private function scrubContext(array $context): array
    {
        $sensitive = ['password', 'secret', 'token', 'authorization', 'api_key', 'apikey'];
        $result = [];
        foreach ($context as $key => $value) {
            $needle = strtolower((string) $key);
            $masked = false;
            foreach ($sensitive as $s) {
                if (str_contains($needle, $s)) {
                    $masked = true;
                    break;
                }
            }
            $result[$key] = $masked ? '[redacted]' : (is_array($value) ? $this->scrubContext($value) : $value);
        }
        return $result;
    }

    private function bindSql(string $sql, array $bindings): string
    {
        if (empty($bindings)) {
            return $sql;
        }
        $i = 0;
        return (string) preg_replace_callback('/\?/', function () use ($bindings, &$i) {
            if (!array_key_exists($i, $bindings)) {
                return '?';
            }
            $value = $bindings[$i++];
            if (is_string($value)) {
                return "'" . str_replace("'", "''", $value) . "'";
            }
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }
            if ($value === null) {
                return 'NULL';
            }
            return (string) $value;
        }, $sql);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function withDuplicates(array $items): array
    {
        $counts = [];
        foreach ($items as $q) {
            $sig = $q['bound_sql'] ?? $q['sql'];
            $counts[$sig] = ($counts[$sig] ?? 0) + 1;
        }
        $out = [];
        foreach ($items as $q) {
            $sig = $q['bound_sql'] ?? $q['sql'];
            $out[] = $q + [
                'is_duplicate' => ($counts[$sig] ?? 1) > 1,
                'duplicate_count' => $counts[$sig] ?? 1,
            ];
        }
        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function countDuplicates(array $items): int
    {
        $counts = [];
        foreach ($items as $q) {
            $sig = $q['bound_sql'] ?? $q['sql'];
            $counts[$sig] = ($counts[$sig] ?? 0) + 1;
        }
        return count(array_filter($counts, static fn (int $c) => $c > 1));
    }

    private function highestLogLevel(): ?string
    {
        static $priority = [
            'debug' => 1, 'info' => 2, 'notice' => 3, 'warning' => 4,
            'error' => 5, 'critical' => 6, 'alert' => 7, 'emergency' => 8,
        ];
        $highest = null;
        $highestP = 0;
        foreach ($this->logsByLevel as $level => $count) {
            if ($count > 0 && ($priority[$level] ?? 0) > $highestP) {
                $highestP = $priority[$level];
                $highest = $level;
            }
        }
        return $highest;
    }

    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1' || $limit === '') {
            return -1;
        }
        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;
        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private function opcacheMb(): ?int
    {
        if (!function_exists('opcache_get_configuration')) {
            return null;
        }
        try {
            $config = @opcache_get_configuration();
            return is_array($config) ? (int) ($config['directives']['opcache.memory_consumption'] ?? 0) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function memoryRatio(): float
    {
        $peak = memory_get_peak_usage(true);
        $limit = $this->parseMemoryLimit((string) (ini_get('memory_limit') ?: '-1'));
        if ($limit <= 0) {
            return 0.0;
        }
        return round($peak / $limit, 3);
    }

    private function truncate(string $value, int $max): string
    {
        return strlen($value) > $max ? substr($value, 0, $max) . '…' : $value;
    }
}
