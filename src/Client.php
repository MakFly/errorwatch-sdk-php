<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk;

use ErrorWatch\Sdk\Event\Event;
use ErrorWatch\Sdk\Event\Severity;
use ErrorWatch\Sdk\Exception\StacktraceBuilder;
use ErrorWatch\Sdk\Tracing\TraceContext;
use ErrorWatch\Sdk\Transport\HttpTransport;
use ErrorWatch\Sdk\Transport\NullTransport;
use ErrorWatch\Sdk\Transport\TransportInterface;

class Client
{
    private readonly Scope             $scope;
    private readonly TransportInterface $transport;
    private readonly StacktraceBuilder  $stacktraceBuilder;
    private ?TraceContext $traceContext = null;

    public function __construct(
        private readonly Options $options,
        ?TransportInterface      $transport = null,
    ) {
        $this->scope = new Scope($options->getMaxBreadcrumbs());

        $this->stacktraceBuilder = new StacktraceBuilder($options->getProjectRoot());

        if ($transport !== null) {
            $this->transport = $transport;
        } elseif (!$options->isEnabled()) {
            $this->transport = new NullTransport();
        } else {
            $this->transport = new HttpTransport(
                $options->getEndpoint(),
                $options->getApiKey(),
                $options->getTimeout(),
            );
        }
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Capture a Throwable and send it to ErrorWatch.
     *
     * @return string|null  The generated event_id, or null if not sent.
     */
    public function captureException(\Throwable $e, ?Scope $scope = null): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        // Self-capture guard: ignore exceptions originating from the SDK itself
        if ($this->isFromSdkNamespace($e)) {
            return null;
        }

        // Sampling
        if (!$this->shouldSample()) {
            return null;
        }

        try {
            $event = Event::fromException($e, $this->options);
        } catch (\Throwable) {
            return null;
        }

        return $this->processAndSend($event, $scope);
    }

    /**
     * Capture a plain message and send it to ErrorWatch.
     *
     * @return string|null  The generated event_id, or null if not sent.
     */
    public function captureMessage(
        string   $msg,
        Severity $level = Severity::INFO,
        ?Scope   $scope = null,
    ): ?string {
        if (!$this->isEnabled()) {
            return null;
        }

        if (!$this->shouldSample()) {
            return null;
        }

        try {
            $event = Event::fromMessage($msg, $level);
            $event->setEnvironment($this->options->getEnvironment());
            $event->setRelease($this->options->getRelease());
            $event->setServerName($this->options->getServerName());
        } catch (\Throwable) {
            return null;
        }

        return $this->processAndSend($event, $scope);
    }

    public function getScope(): Scope
    {
        return $this->scope;
    }

    public function configureScope(callable $cb): void
    {
        try {
            $cb($this->scope);
        } catch (\Throwable) {
            // Silent — scope configuration must never crash the app
        }
    }

    /**
     * Flush pending transport operations (no-op for synchronous transports).
     */
    public function flush(): void
    {
        // Reserved for async / batched transport implementations
    }

    /**
     * Reset scope state — useful for long-running processes (Octane, RoadRunner).
     */
    public function resetState(): void
    {
        $this->scope->clear();
    }

    public function isEnabled(): bool
    {
        return $this->options->isEnabled();
    }

    /**
     * Inject the request-scoped trace context so outgoing events can
     * carry `trace_id` / `span_id` for distributed correlation.
     */
    public function setTraceContext(?TraceContext $traceContext): void
    {
        $this->traceContext = $traceContext;
    }

    public function getTraceContext(): ?TraceContext
    {
        return $this->traceContext;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function processAndSend(Event $event, ?Scope $extraScope): ?string
    {
        try {
            // Apply the global scope
            $this->scope->applyToEvent($event);

            // Apply an optional per-call scope
            if ($extraScope !== null) {
                $extraScope->applyToEvent($event);
            }

            $payload = $event->toPayload();

            // Merge distributed tracing correlation so logs/errors/spans
            // emitted during the same request can be linked on the dashboard.
            if (null !== $this->traceContext && $this->traceContext->hasContext()) {
                $payload['trace_id'] = $this->traceContext->getTraceId();
                $payload['span_id'] = $this->traceContext->getCurrentSpanId();
            }

            // beforeSend hook — returning null drops the event
            $beforeSend = $this->options->getBeforeSend();
            if ($beforeSend !== null) {
                try {
                    $result = $beforeSend($payload);
                    if ($result === null) {
                        return null;
                    }
                    if (is_array($result)) {
                        $payload = $result;
                    }
                } catch (\Throwable) {
                    // beforeSend must not crash the SDK
                }
            }

            $sent = $this->transport->send($payload);

            return $sent ? ($payload['event_id'] ?? $event->getEventId()) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function shouldSample(): bool
    {
        $rate = $this->options->getSampleRate();

        if ($rate >= 1.0) {
            return true;
        }
        if ($rate <= 0.0) {
            return false;
        }

        return (mt_rand(0, PHP_INT_MAX - 1) / PHP_INT_MAX) < $rate;
    }

    private function isFromSdkNamespace(\Throwable $e): bool
    {
        return str_starts_with(get_class($e), 'ErrorWatch\\');
    }
}
