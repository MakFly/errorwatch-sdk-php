<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Client;

use ErrorWatch\Laravel\Breadcrumbs\BreadcrumbManager;
use ErrorWatch\Laravel\Context\UserContext;
use ErrorWatch\Laravel\Jobs\SendEventJob;
use ErrorWatch\Laravel\Tracing\RequestTracer;
use ErrorWatch\Laravel\Tracing\Span;
use ErrorWatch\Laravel\Tracing\TraceContext;
use ErrorWatch\Laravel\Transport\HttpTransport;
use ErrorWatch\Laravel\Transport\QueueDispatchingTransport;
use ErrorWatch\Laravel\Transport\TransportDelegate;
use ErrorWatch\Sdk\Client as SdkClient;
use ErrorWatch\Sdk\Options as SdkOptions;
use ErrorWatch\Sdk\Transport\AsyncTransportInterface;
use SplObjectStorage;
use Throwable;

/**
 * Laravel-specific monitoring client.
 *
 * Wraps the core ErrorWatch\Sdk\Client for event capture, while adding
 * Laravel-specific features: APM transaction tracing, rich breadcrumbs,
 * circuit-breaker/retry transport, and Octane-friendly state management.
 */
class MonitoringClient
{
    public const VERSION = '0.2.0';

    protected array $config;
    protected HttpTransport $transport;

    /**
     * Mode-aware transport delegate: a QueueDispatchingTransport in `queue`
     * mode, a plain TransportDelegate otherwise. Send paths that bypass the
     * core SDK pipeline (captureEvent) must route through this so `queue`
     * mode is honoured instead of issuing in-request HTTP.
     */
    private AsyncTransportInterface $sdkTransportDelegate;

    protected BreadcrumbManager $breadcrumbs;
    protected UserContext $userContext;
    protected RequestTracer $tracer;
    protected ?Span $currentTransaction = null;

    /** Core SDK client used for event capture (uses the same Laravel transport). */
    private SdkClient $sdkClient;

    /** @var SplObjectStorage<Throwable, true> Tracks already-captured exceptions to prevent duplicates */
    private SplObjectStorage $capturedExceptions;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->capturedExceptions = new SplObjectStorage();

        $this->transport = new HttpTransport(
            $config['endpoint'] ?? '',
            $config['api_key'] ?? '',
            $config['transport']['timeout'] ?? 5,
            $config['transport']['circuit_breaker_threshold'] ?? 3,
            $config['transport']['circuit_breaker_cooldown'] ?? 30,
            $config['transport']['retry_attempts'] ?? 2,
            $config['transport']['request_budget_ms'] ?? 50,
            protocol: $config['protocol'] ?? 'envelope',
        );

        $this->breadcrumbs = new BreadcrumbManager(
            $config['breadcrumbs']['max_count'] ?? 100
        );

        $this->userContext = new UserContext();
        $this->tracer = new RequestTracer();

        // Initialise the core SDK client with a delegate transport that always
        // resolves to $this->transport. This ensures that when tests replace
        // $this->transport via reflection, the core SDK uses the new transport.
        $sdkOptions = $this->buildSdkOptions($config);
        $resolvedMode = $this->resolveTransportMode();
        $config['transport']['mode'] = $resolvedMode;
        $this->config = $config;

        // In batch mode the single transport accumulates every item (events,
        // logs, transactions) and ships them as one POST /api/v1/batch at
        // flush time — no per-item HTTP, no app-side queue/worker.
        if ($resolvedMode === 'batch') {
            $this->transport->enableBatchMode();
        }

        $resolver = fn () => $this->transport;
        $delegate = $resolvedMode === 'queue'
            ? new QueueDispatchingTransport(
                $resolver,
                $config['transport']['queue_connection'] ?? null,
                $config['transport']['queue_name'] ?? 'default',
            )
            : new TransportDelegate($resolver);
        $this->sdkTransportDelegate = $delegate;
        $this->sdkClient = new SdkClient($sdkOptions, $delegate);
    }

    /**
     * Resolve `transport.mode = auto` to a concrete strategy:
     * - 'queue' if Laravel's default queue connection is NOT 'sync' and
     *   the dispatch() helper is available (real queue worker available).
     * - 'async' otherwise (Guzzle fire-and-forget, drained on terminate).
     *
     * Explicit modes (sync / async / queue) are returned untouched.
     */
    private function resolveTransportMode(): string
    {
        $mode = strtolower((string) ($this->config['transport']['mode'] ?? 'async'));
        if ($mode !== 'auto') {
            return in_array($mode, ['sync', 'async', 'queue', 'batch'], true) ? $mode : 'async';
        }

        try {
            if (function_exists('config') && function_exists('dispatch')) {
                $default = \config('queue.default');
                if (is_string($default) && $default !== '' && $default !== 'sync' && $default !== 'null') {
                    return 'queue';
                }
            }
        } catch (\Throwable) {
            // fall through to async
        }
        return 'async';
    }

    // -------------------------------------------------------------------------
    // Core SDK access
    // -------------------------------------------------------------------------

    /**
     * Return the underlying core SDK client.
     * Useful for low-level access (e.g. configureScope).
     */
    public function getSdkClient(): SdkClient
    {
        return $this->sdkClient;
    }

    // -------------------------------------------------------------------------
    // State checks
    // -------------------------------------------------------------------------

    /**
     * Check if the SDK is enabled.
     */
    public function isEnabled(): bool
    {
        return ($this->config['enabled'] ?? true) === true;
    }

    /**
     * Check if an event should be sampled based on the rate.
     */
    public function shouldSample(float $rate): bool
    {
        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        return (mt_rand(0, 100) / 100.0) <= $rate;
    }

    /**
     * Get a configuration value.
     */
    public function getConfig(string $key, mixed $default = null): mixed
    {
        $value = $this->config;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    // -------------------------------------------------------------------------
    // Event capture — delegated to core SDK
    // -------------------------------------------------------------------------

    /**
     * Capture an exception.
     * Returns null if disabled, already captured, or filtered by before_send.
     *
     * Delegates to the core SDK client which handles sampling, scope application,
     * and transport. Laravel-specific context (breadcrumbs, user) is applied via
     * configureScope before forwarding to the core client.
     */
    public function captureException(Throwable $exception, array $context = []): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        // Deduplicate: skip if this exact exception instance was already captured
        if ($this->capturedExceptions->contains($exception)) {
            return null;
        }

        // Prevent self-capture: skip exceptions originating from the SDK itself
        if ($this->isInternalException($exception)) {
            return null;
        }

        $this->capturedExceptions->attach($exception);

        // Enrich the core scope with breadcrumbs + user context
        $this->syncScopeToSdkClient();

        // Build an extra per-call scope for context passed by the caller
        $extraScope = null;
        if (!empty($context['tags']) || !empty($context['extra']) || !empty($context['url']) || !empty($context['status_code']) || !empty($context['profile'])) {
            $extraScope = new \ErrorWatch\Sdk\Scope();

            if (!empty($context['tags'])) {
                $extraScope->setTags($context['tags']);
            }
            if (!empty($context['extra'])) {
                $extraScope->setExtras($context['extra']);
            }
            if (!empty($context['status_code'])) {
                $statusCode = (int) $context['status_code'];
                $extraScope->setTag('http.status_code', (string) $statusCode);
                $extraScope->setStatusCode($statusCode);
            }
            if (!empty($context['profile']) && is_array($context['profile'])) {
                $extraScope->setProfile($context['profile']);
            }
            // Attach trace context if there is an active transaction
            if ($this->currentTransaction !== null) {
                $extraScope->setExtra('session_id', $this->currentTransaction->getTraceId());
            }
        }

        return $this->sdkClient->captureException($exception, $extraScope);
    }

    /**
     * Capture a message.
     */
    public function captureMessage(string $message, string $level = 'info', array $context = []): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        // Map level string to core Severity enum
        $severity = $this->mapLevelToSeverity($level);

        // Enrich the core scope with current breadcrumbs + user
        $this->syncScopeToSdkClient();

        // Build a per-call scope for caller-supplied context
        $extraScope = null;
        if (!empty($context['tags']) || !empty($context['extra']) || !empty($context['profile']) || !empty($context['frames'])) {
            $extraScope = new \ErrorWatch\Sdk\Scope();

            if (!empty($context['tags'])) {
                $extraScope->setTags($context['tags']);
            }
            if (!empty($context['extra'])) {
                $extraScope->setExtras($context['extra']);
            }
            if (!empty($context['profile']) && is_array($context['profile'])) {
                $extraScope->setProfile($context['profile']);
            }
            if (!empty($context['frames']) && is_array($context['frames'])) {
                $extraScope->setFrames($context['frames']);
            }
        }

        return $this->sdkClient->captureMessage($message, $severity, $extraScope);
    }

    /**
     * Capture a custom event (raw payload).
     * This bypasses the core SDK pipeline and sends directly via transport.
     */
    public function captureEvent(array $event): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        // Add defaults
        $event = array_merge([
            'timestamp' => microtime(true),
            'environment' => $this->config['environment'] ?? 'production',
            'release' => $this->config['release'] ?? null,
            'server_name' => $this->config['server_name'] ?? gethostname() ?: 'unknown',
            'breadcrumbs' => $this->formatBreadcrumbsForApi(),
            'user' => $this->userContext->getUser(),
            'tags' => $this->applicationTags(),
            'extra' => $this->applicationExtras(),
        ], $event);

        // Route through the mode-aware delegate so `queue` mode dispatches a
        // SendEventJob instead of issuing an in-request HTTP call. In sync /
        // async modes the delegate forwards to $this->transport unchanged.
        if (($this->config['transport']['mode'] ?? 'async') === 'sync') {
            $this->sdkTransportDelegate->send($event);
        } else {
            $this->sdkTransportDelegate->sendAsync($event);
        }

        return $event['event_id'] ?? null;
    }

    // -------------------------------------------------------------------------
    // Breadcrumbs
    // -------------------------------------------------------------------------

    /**
     * Add a breadcrumb.
     */
    public function addBreadcrumb(string $message, string $type = 'default', array $data = []): void
    {
        if (!$this->isEnabled() || !($this->config['breadcrumbs']['enabled'] ?? true)) {
            return;
        }

        $this->breadcrumbs->add($message, $type, 'default', $data);
    }

    /**
     * Get all breadcrumbs.
     */
    public function getBreadcrumbs(): array
    {
        return $this->breadcrumbs->all();
    }

    /**
     * Clear all breadcrumbs.
     */
    public function clearBreadcrumbs(): void
    {
        $this->breadcrumbs->clear();
        $this->sdkClient->getScope()->clear();
    }

    // -------------------------------------------------------------------------
    // User context
    // -------------------------------------------------------------------------

    /**
     * Set the current user.
     */
    public function setUser(array $user): void
    {
        if (!($this->config['user_context']['enabled'] ?? true)) {
            return;
        }

        $this->userContext->setUser($user);

        // Sync to core scope
        $this->sdkClient->getScope()->setUser($user);
    }

    /**
     * Snapshot the current HTTP request into the SDK scope so every event
     * captured during this request lifecycle carries `request.url` /
     * `request.method` / status_code at the payload top-level.
     *
     * @param array{url?: string, method?: string, headers?: array<string,mixed>, query_string?: string} $request
     */
    public function setRequestContext(array $request, ?int $statusCode = null): void
    {
        $scope = $this->sdkClient->getScope();
        $scope->setRequest($request);

        if ($statusCode !== null) {
            $scope->setStatusCode($statusCode);
            $scope->setTag('http.status_code', (string) $statusCode);
        } else {
            // Entry-of-request snapshot ($statusCode === null): explicitly drop
            // any status carried over from a previous request. Long-running
            // workers (Octane/RoadRunner) reuse this scope, so a stale 200/500
            // would otherwise leak onto pre-response captures of this request.
            $scope->setStatusCode(null);
            $scope->removeTag('http.status_code');
        }

        $this->syncApplicationContextToScope($scope);
    }

    /**
     * Read the current HTTP request context snapshot (url + final status code)
     * from the SDK scope. Used by the live-log path so log items carry the
     * same request.url / status_code the event path already gets, letting the
     * backend filter logs by HTTP status.
     *
     * @return array{url: ?string, status_code: ?int}
     */
    public function getRequestContext(): array
    {
        $scope   = $this->sdkClient->getScope();
        $request = $scope->getRequest();

        return [
            'url'         => $request['url'] ?? null,
            'status_code' => $scope->getStatusCode(),
        ];
    }

    /**
     * Get the current user.
     */
    public function getUser(): ?array
    {
        return $this->userContext->getUser();
    }

    /**
     * Clear the user context.
     */
    public function clearUser(): void
    {
        $this->userContext->clearUser();
        $this->sdkClient->getScope()->setUser([]);
    }

    // -------------------------------------------------------------------------
    // APM transactions
    // -------------------------------------------------------------------------

    /**
     * Start a new transaction for APM.
     */
    public function startTransaction(string $name): Span
    {
        $traceContext = TraceContext::generate();
        $this->currentTransaction = new Span($name, $traceContext);

        return $this->currentTransaction;
    }

    /**
     * Get the current transaction.
     */
    public function getCurrentTransaction(): ?Span
    {
        return $this->currentTransaction;
    }

    /**
     * Finish the current transaction and send it.
     */
    public function finishTransaction(): ?array
    {
        if ($this->currentTransaction === null) {
            return null;
        }

        $this->currentTransaction->finish();
        $transactionData = $this->currentTransaction->toArray();

        // Send transaction data (fire-and-forget by default — middleware
        // terminate runs after fastcgi_finish_request so the client has
        // already received the response, but the host process should not
        // wait on APM I/O either way).
        if ($this->shouldSample($this->config['apm']['sample_rate'] ?? 1.0)) {
            $env  = $this->config['environment'] ?? 'production';
            $mode = $this->config['transport']['mode'] ?? 'async';
            if ($mode === 'sync') {
                $this->transport->sendTransaction($transactionData, $env);
            } elseif ($mode === 'queue') {
                // Transactions are not part of TransportInterface, so the
                // QueueDispatchingTransport delegate cannot carry them —
                // dispatch the job directly, falling back to async HTTP.
                $this->dispatchSendEventJob(
                    'transaction',
                    $transactionData,
                    $env,
                    fn () => $this->transport->sendTransactionAsync($transactionData, $env),
                );
            } else {
                $this->transport->sendTransactionAsync($transactionData, $env);
            }
        }

        $this->currentTransaction = null;

        return $transactionData;
    }

    /**
     * Deliver a live log entry, honouring the resolved transport mode.
     *
     * In `queue` mode the entry is dispatched as a SendEventJob('log') so the
     * web request lifecycle spends no time on log I/O — mirroring how events
     * and transactions are routed. In sync / async modes the behaviour is
     * unchanged (synchronous sendLog()).
     *
     * @param array<string, mixed> $logEntry
     */
    public function deliverLog(array $logEntry): void
    {
        if (($this->config['transport']['mode'] ?? 'async') === 'queue') {
            $this->dispatchSendEventJob(
                'log',
                $logEntry,
                null,
                fn () => $this->transport->sendLog($logEntry),
            );
            return;
        }

        $this->transport->sendLog($logEntry);
    }

    /**
     * Dispatch a SendEventJob for queue-mode delivery of payloads that bypass
     * the core SDK pipeline (APM transactions, live logs). Applies the
     * configured queue connection / name and falls back to $fallback if the
     * dispatch itself fails (e.g. broken queue connection) so an event is
     * never silently lost.
     *
     * @param 'event'|'transaction'|'log' $kind
     * @param array<string, mixed>        $payload
     * @param callable():void             $fallback
     */
    private function dispatchSendEventJob(string $kind, array $payload, ?string $env, callable $fallback): void
    {
        try {
            if (function_exists('dispatch')) {
                $job = new SendEventJob($kind, $payload, $env);
                $connection = $this->config['transport']['queue_connection'] ?? null;
                if ($connection !== null) {
                    $job->onConnection($connection);
                }
                $job->onQueue($this->config['transport']['queue_name'] ?? 'default');
                \dispatch($job);
                return;
            }
        } catch (\Throwable $e) {
            error_log('[ErrorWatch] Queue dispatch failed, falling back: ' . $e->getMessage());
        }

        $fallback();
    }

    // -------------------------------------------------------------------------
    // Accessors (for services that depend on internals)
    // -------------------------------------------------------------------------

    /**
     * Get the breadcrumb manager.
     */
    public function getBreadcrumbManager(): BreadcrumbManager
    {
        return $this->breadcrumbs;
    }

    /**
     * Get the user context.
     */
    public function getUserContext(): UserContext
    {
        return $this->userContext;
    }

    /**
     * Get the HTTP transport.
     */
    public function getTransport(): HttpTransport
    {
        return $this->transport;
    }

    /**
     * Clear the captured exceptions tracker (for Octane between-request reset).
     */
    public function clearCapturedExceptions(): void
    {
        $this->capturedExceptions = new SplObjectStorage();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build core SDK options from the Laravel config array.
     */
    private function buildSdkOptions(array $config): SdkOptions
    {
        // Options requires non-empty endpoint + api_key; provide safe defaults
        // when running in a context where these may not yet be configured (e.g. testing).
        $normalized = array_merge([
            'endpoint'       => 'https://errorwatch.io',
            'api_key'        => 'disabled',
            'enabled'        => $config['enabled'] ?? true,
            'environment'    => $config['environment'] ?? 'production',
            'release'        => $config['release'] ?? null,
            'server_name'    => $config['server_name'] ?? gethostname() ?: 'unknown',
            'sample_rate'    => $config['sample_rate'] ?? 1.0,
            'max_breadcrumbs' => $config['breadcrumbs']['max_count'] ?? 100,
            'timeout'        => $config['transport']['timeout'] ?? 5,
            'transport_mode' => $config['transport']['mode'] ?? 'async',
            'request_budget_ms' => $config['transport']['request_budget_ms'] ?? 50,
            'protocol'       => $config['protocol'] ?? 'envelope',
        ], array_filter([
            'endpoint'     => $config['endpoint'] ?? null,
            'api_key'      => $config['api_key'] ?? null,
            'before_send'  => $config['before_send'] ?? null,
            'project_root' => $config['project_root']
                ?? (function_exists('base_path') ? base_path() : null),
        ]));

        return new SdkOptions($normalized);
    }

    /**
     * Sync the BreadcrumbManager and UserContext into the core SDK Scope.
     * Called before every capture so the core scope reflects the latest Laravel state.
     */
    private function syncScopeToSdkClient(): void
    {
        $scope = $this->sdkClient->getScope();

        // Sync user
        $user = $this->userContext->getUser();
        if ($user !== null) {
            $scope->setUser($user);
        }

        // Sync breadcrumbs: convert Laravel breadcrumb format to core Breadcrumb objects
        if ($this->config['breadcrumbs']['enabled'] ?? true) {
            $breadcrumbs = $this->breadcrumbs->all();
            // Scope::clear() wipes breadcrumbs (intended — avoids duplicating
            // them on every capture) but also wipes the request snapshot +
            // status_code set by ErrorWatchMiddleware::setRequestContext().
            // Capture that request context before clearing and re-apply it
            // after, so events keep request.url / request.method / status_code.
            $request    = $scope->getRequest();
            $statusCode = $scope->getStatusCode();

            $scope->clear();

            if ($user !== null) {
                $scope->setUser($user);
            }
            if ($request !== null) {
                $scope->setRequest($request);
            }
            if ($statusCode !== null) {
                $scope->setStatusCode($statusCode);
                $scope->setTag('http.status_code', (string) $statusCode);
            }

            foreach ($breadcrumbs as $bc) {
                $level = $this->mapLevelToSeverity($bc['level'] ?? 'info');
                $crumb = new \ErrorWatch\Sdk\Breadcrumb\Breadcrumb(
                    category: $bc['category'] ?? 'default',
                    message:  $bc['message'] ?? null,
                    type:     $bc['type'] ?? null,
                    level:    $level,
                    data:     $bc['data'] ?? [],
                );
                $scope->addBreadcrumb($crumb);
            }
        }

        $this->syncApplicationContextToScope($scope);
    }

    private function syncApplicationContextToScope(\ErrorWatch\Sdk\Scope $scope): void
    {
        $tags = $this->applicationTags();
        if (!empty($tags)) {
            $scope->setTags($tags);
        }

        $extra = $this->applicationExtras();
        if (!empty($extra)) {
            $scope->setExtras($extra);
        }
    }

    /**
     * @return array<string, string>
     */
    private function applicationTags(): array
    {
        $tags = [];

        $environment = $this->config['environment'] ?? null;
        if (is_scalar($environment) && (string) $environment !== '') {
            $tags['app.environment'] = (string) $environment;
        }

        $release = $this->config['release'] ?? null;
        if (is_scalar($release) && (string) $release !== '') {
            $tags['release'] = (string) $release;
        }

        $git = is_array($this->config['git'] ?? null) ? $this->config['git'] : [];
        foreach (['commit', 'branch', 'dirty'] as $key) {
            $value = $git[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                $tags["git.{$key}"] = (string) $value;
                $tags[$key] = (string) $value;
            }
        }

        return $tags;
    }

    /**
     * @return array<string, mixed>
     */
    private function applicationExtras(): array
    {
        $extra = [
            'application' => array_filter([
                'environment' => $this->config['environment'] ?? null,
                'release' => $this->config['release'] ?? null,
                'server_name' => $this->config['server_name'] ?? gethostname() ?: 'unknown',
            ], static fn ($value) => $value !== null && $value !== ''),
        ];

        $git = is_array($this->config['git'] ?? null) ? array_filter(
            $this->config['git'],
            static fn ($value) => $value !== null && $value !== ''
        ) : [];

        if (!empty($git)) {
            $extra['git'] = $git;
        }

        return array_filter($extra, static fn ($value) => !empty($value));
    }

    /**
     * Map a string level to the core Severity enum.
     */
    private function mapLevelToSeverity(string $level): \ErrorWatch\Sdk\Event\Severity
    {
        return match (strtolower($level)) {
            'fatal'         => \ErrorWatch\Sdk\Event\Severity::FATAL,
            'error'         => \ErrorWatch\Sdk\Event\Severity::ERROR,
            'warning', 'warn' => \ErrorWatch\Sdk\Event\Severity::WARNING,
            'debug'         => \ErrorWatch\Sdk\Event\Severity::DEBUG,
            default         => \ErrorWatch\Sdk\Event\Severity::INFO,
        };
    }

    /**
     * Format breadcrumbs to match the API schema (for captureEvent).
     */
    protected function formatBreadcrumbsForApi(): array
    {
        $breadcrumbs = $this->breadcrumbs->all();

        return array_map(function (array $breadcrumb) {
            $validCategories = ['ui', 'navigation', 'console', 'http', 'user'];
            $category = $breadcrumb['category'] ?? 'user';
            if (!in_array($category, $validCategories, true)) {
                $category = 'user';
            }

            return [
                'timestamp' => (int)($breadcrumb['timestamp'] ?? microtime(true) * 1000),
                'category' => $category,
                'type' => $breadcrumb['type'] ?? null,
                'level' => $breadcrumb['level'] ?? 'info',
                'message' => $breadcrumb['message'] ?? null,
                'data' => $breadcrumb['data'] ?? null,
            ];
        }, $breadcrumbs);
    }

    /**
     * Check if an exception originates from the SDK itself.
     * Prevents self-capture loops where SDK errors get reported as app errors.
     */
    protected function isInternalException(Throwable $exception): bool
    {
        // Check the exception class namespace (SDK exception types only, not app exceptions)
        $class = get_class($exception);
        if (str_starts_with($class, 'ErrorWatch\\Laravel\\') && !str_starts_with($class, 'ErrorWatch\\Laravel\\Tests\\')) {
            return true;
        }
        if (str_starts_with($class, 'ErrorWatch\\Sdk\\') && !str_starts_with($class, 'ErrorWatch\\Sdk\\Tests\\')) {
            return true;
        }

        // Check if the exception was thrown from an SDK vendor file
        $file = $exception->getFile();
        if (str_contains($file, 'vendor/errorwatch/sdk-laravel/src/')) {
            return true;
        }
        if (str_contains($file, 'vendor/errorwatch/sdk-php/src/')) {
            return true;
        }

        return false;
    }
}
