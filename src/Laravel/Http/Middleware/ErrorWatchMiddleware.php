<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Http\Middleware;

use Closure;
use ErrorWatch\Laravel\Client\MonitoringClient;
use ErrorWatch\Laravel\Profiler\RequestProfile;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ErrorWatchMiddleware
{
    protected MonitoringClient $client;
    protected bool $apmEnabled;
    protected array $excludedRoutes;
    public function __construct(MonitoringClient $client)
    {
        $this->client = $client;
        $this->apmEnabled = $client->getConfig('apm.enabled', true);
        $this->excludedRoutes = $client->getConfig('apm.excluded_routes', []);
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // Skip if SDK is disabled
        if (!$this->client->isEnabled()) {
            return $next($request);
        }

        // Check if route is excluded
        if ($this->isExcludedRoute($request)) {
            return $next($request);
        }

        // Snapshot request into the SDK scope so every event captured during
        // this lifecycle carries request.url / request.method at the payload
        // top-level (and the dashboard "HTTP" column shows the real status).
        try {
            $this->client->setRequestContext([
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'query_string' => (string) ($request->getQueryString() ?? ''),
            ]);
        } catch (\Throwable) {
            // never break the request
        }

        // Start per-request profile bag
        if ($this->client->getConfig('profiler.enabled', false)) {
            try {
                app(RequestProfile::class)->start($request);
            } catch (\Throwable) {
                // never break the request
            }
        }

        // Start transaction for APM
        if ($this->apmEnabled) {
            $route = $request->route()?->uri() ?? $request->path();
            $transaction = $this->client->startTransaction("{$request->method()} {$route}");

            // Use LARAVEL_START for accurate timing (includes bootstrap + all middleware)
            if (defined('LARAVEL_START')) {
                $transaction->overrideStartTimestamp(LARAVEL_START * 1000);
            }
        }

        // Add request breadcrumb
        if ($this->client->getConfig('breadcrumbs.enabled', true)) {
            $this->client->getBreadcrumbManager()->addRequest(
                $request->method(),
                $request->fullUrl(),
                0 // Will be updated in terminate()
            );
        }

        // Set user context if authenticated
        if ($this->client->getConfig('user_context.enabled', true) && $request->user()) {
            $this->setUserFromRequest($request);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // Do NOT capture the exception here — ErrorWatchExceptionHandler::report() handles it.
            // This prevents double-capture when both middleware and handler are active.

            // Finish transaction with error status
            if ($this->apmEnabled) {
                $transaction = $this->client->getCurrentTransaction();
                if ($transaction) {
                    $transaction->setError($e->getMessage());
                }
            }

            throw $e;
        }

        // Bubble the response status back into the SDK scope so events
        // emitted during terminate() (or async tail) tag the correct code.
        try {
            $this->client->setRequestContext([
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'query_string' => (string) ($request->getQueryString() ?? ''),
            ], $response->getStatusCode());
        } catch (\Throwable) {
            // never break the response
        }

        return $response;
    }

    /**
     * Handle tasks after the response has been sent.
     */
    public function terminate(Request $request, Response $response): void
    {
        // Skip if SDK is disabled
        if (!$this->client->isEnabled()) {
            return;
        }

        // Finish transaction
        if ($this->apmEnabled) {
            $transaction = $this->client->getCurrentTransaction();

            if ($transaction) {
                // Set response status
                $transaction->setTag('http.status_code', $response->status());
                $transaction->setData('response_size', strlen($response->getContent() ?? ''));

                // Mark status based on response code
                if ($response->status() >= 500) {
                    $transaction->setError("HTTP {$response->status()}");
                } elseif ($response->status() >= 400) {
                    $transaction->setStatus('unknown_error', "HTTP {$response->status()}");
                } else {
                    $transaction->setOk();
                }

                $this->client->finishTransaction();
            }
        }

        // Flush any pending async events before the process ends
        $this->client->getTransport()->flushAsync();
    }

    /**
     * Check if the route is excluded from tracking.
     */
    protected function isExcludedRoute(Request $request): bool
    {
        $path = $request->path();

        foreach ($this->excludedRoutes as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Set user context from the authenticated request.
     */
    protected function setUserFromRequest(Request $request): void
    {
        $user = $request->user();

        if (!$user) {
            return;
        }

        $userData = [
            'id' => $user->getAuthIdentifier(),
        ];

        // Add email if available
        if (isset($user->email)) {
            $userData['email'] = $user->email;
        }

        // Add username/name if available
        if (isset($user->name)) {
            $userData['username'] = $user->name;
        } elseif (isset($user->username)) {
            $userData['username'] = $user->username;
        }

        // Add IP address if configured
        if ($this->client->getConfig('user_context.capture_ip', true)) {
            $userData['ip_address'] = $request->ip();
        }

        $this->client->setUser($userData);
    }
}
