<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Client;

use ErrorWatch\Laravel\Breadcrumbs\BreadcrumbManager;
use ErrorWatch\Laravel\Context\UserContext;
use ErrorWatch\Laravel\Tracing\RequestTracer;
use ErrorWatch\Laravel\Tracing\Span;
use ErrorWatch\Laravel\Tracing\TraceContext;
use ErrorWatch\Laravel\Transport\HttpTransport;
use ErrorWatch\Laravel\Transport\TransportDelegate;
use ErrorWatch\Sdk\Client as SdkClient;
use ErrorWatch\Sdk\Options as SdkOptions;
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
            $config['transport']['circuit_breaker_threshold'] ?? 5,
            $config['transport']['circuit_breaker_cooldown'] ?? 60,
            $config['transport']['retry_attempts'] ?? 2,
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
        $delegate = new TransportDelegate(fn () => $this->transport);
        $this->sdkClient = new SdkClient($sdkOptions, $delegate);
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
                $extraScope->setExtra('status_code', $statusCode);
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
            'breadcrumbs' => $this->formatBreadcrumbsForApi(),
            'user' => $this->userContext->getUser(),
        ], $event);

        $this->transport->send($event);

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

        // Send transaction data
        if ($this->shouldSample($this->config['apm']['sample_rate'] ?? 1.0)) {
            $this->transport->sendTransaction($transactionData, $this->config['environment'] ?? 'production');
        }

        $this->currentTransaction = null;

        return $transactionData;
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
            'sample_rate'    => $config['sample_rate'] ?? 1.0,
            'max_breadcrumbs' => $config['breadcrumbs']['max_count'] ?? 100,
            'timeout'        => $config['transport']['timeout'] ?? 5,
        ], array_filter([
            'endpoint'    => $config['endpoint'] ?? null,
            'api_key'     => $config['api_key'] ?? null,
            'before_send' => $config['before_send'] ?? null,
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
            // Clear and re-add — scope::clear() resets breadcrumbs but also clears
            // tags/user, so we only replace breadcrumbs via a fresh BreadcrumbBag trick.
            // Instead, we call Scope::clear() and then re-apply everything.
            $scope->clear();

            if ($user !== null) {
                $scope->setUser($user);
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
