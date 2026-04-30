<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Services;

use ErrorWatch\Laravel\Client\MonitoringClient;
use ErrorWatch\Laravel\Profiler\RequestProfile;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;

class HttpClientListener
{
    protected MonitoringClient $client;
    protected array $pendingRequests = [];
    protected bool $captureErrorsAsBreadcrumbs;

    public function __construct(MonitoringClient $client)
    {
        $this->client = $client;
        $this->captureErrorsAsBreadcrumbs = $client->getConfig(
            'apm.http_client.capture_errors_as_breadcrumbs',
            true
        );
    }

    /**
     * Register the HTTP client listeners.
     */
    public function register(): void
    {
        Event::listen(RequestSending::class, [$this, 'onRequestSending']);
        Event::listen(ResponseReceived::class, [$this, 'onResponseReceived']);
        Event::listen(ConnectionFailed::class, [$this, 'onConnectionFailed']);
    }

    /**
     * Handle outgoing request.
     */
    public function onRequestSending(RequestSending $event): void
    {
        if (!$this->client->isEnabled()) {
            return;
        }

        $requestId = spl_object_id($event->request);

        $this->pendingRequests[$requestId] = [
            'method' => $event->request->method(),
            'url' => $event->request->url(),
            'start_time' => microtime(true),
            'headers' => $this->sanitizeHeaders($event->request->headers()),
        ];
    }

    /**
     * Handle received response.
     */
    public function onResponseReceived(ResponseReceived $event): void
    {
        if (!$this->client->isEnabled()) {
            return;
        }

        $requestId = spl_object_id($event->request);
        $pending = $this->pendingRequests[$requestId] ?? null;

        if ($pending === null) {
            return;
        }

        $durationMs = (microtime(true) - $pending['start_time']) * 1000;
        $statusCode = $event->response->status();

        // Add breadcrumb
        if ($this->client->getConfig('breadcrumbs.enabled', true)) {
            $this->client->getBreadcrumbManager()->addHttp(
                $pending['method'],
                $pending['url'],
                $statusCode,
                $durationMs
            );
        }

        // Add to APM tracing
        if ($this->client->getConfig('apm.enabled', true)) {
            $transaction = $this->client->getCurrentTransaction();

            if ($transaction) {
                $span = $transaction->startChild(
                    "HTTP {$pending['method']} {$pending['url']}",
                    'http.client'
                );

                $span->setTag('http.method', $pending['method']);
                $span->setTag('http.url', $pending['url']);
                $span->setTag('http.status_code', $statusCode);
                $span->setData('duration_ms', $durationMs);

                if ($statusCode >= 400) {
                    $span->setError("HTTP {$statusCode}");

                    // Capture error as breadcrumb if configured
                    if ($this->captureErrorsAsBreadcrumbs) {
                        $this->client->addBreadcrumb(
                            "HTTP error {$statusCode}: {$pending['method']} {$pending['url']}",
                            'error',
                            [
                                'status_code' => $statusCode,
                                'response_body' => $this->sanitizeResponseBody($event->response->body()),
                            ]
                        );
                    }
                } else {
                    $span->setOk();
                }

                $span->finish();
            }
        }

        $this->pushToProfile($pending['method'], $pending['url'], $statusCode, $durationMs);

        unset($this->pendingRequests[$requestId]);
    }

    private function pushToProfile(string $method, string $url, int $statusCode, float $durationMs): void
    {
        if (!$this->client->getConfig('profiler.enabled', false)) {
            return;
        }
        try {
            $profile = app(RequestProfile::class);
            if ($profile->isStarted()) {
                $profile->recordHttpRequest($method, $url, $statusCode, $durationMs);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Handle connection failure.
     */
    public function onConnectionFailed(ConnectionFailed $event): void
    {
        if (!$this->client->isEnabled()) {
            return;
        }

        $requestId = spl_object_id($event->request);
        $pending = $this->pendingRequests[$requestId] ?? null;

        $url = $pending['url'] ?? $event->request->url();
        $method = $pending['method'] ?? $event->request->method();

        // Add breadcrumb for failure
        if ($this->client->getConfig('breadcrumbs.enabled', true)) {
            $this->client->getBreadcrumbManager()->addHttp(
                $method,
                $url,
                0, // Connection failed
                0
            );
        }

        // Add to APM tracing
        if ($this->client->getConfig('apm.enabled', true)) {
            $transaction = $this->client->getCurrentTransaction();

            if ($transaction) {
                $span = $transaction->startChild(
                    "HTTP {$method} {$url}",
                    'http.client'
                );

                $span->setTag('http.method', $method);
                $span->setTag('http.url', $url);
                $span->setError('Connection failed');
                $span->setData('error', 'Connection failed');

                $span->finish();
            }
        }

        $this->pushToProfile($method, $url, 0, 0.0);

        unset($this->pendingRequests[$requestId]);
    }

    /**
     * Sanitize headers to remove sensitive data.
     */
    protected function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = ['authorization', 'cookie', 'set-cookie', 'x-api-key'];

        return array_map(function ($key, $value) use ($sensitiveHeaders) {
            if (in_array(strtolower($key), $sensitiveHeaders, true)) {
                return '[redacted]';
            }

            return $value;
        }, array_keys($headers), $headers);
    }

    /**
     * Sanitize response body for logging.
     */
    protected function sanitizeResponseBody(string $body): string
    {
        // Truncate long responses
        if (strlen($body) > 500) {
            return substr($body, 0, 500) . '... [truncated]';
        }

        return $body;
    }
}
