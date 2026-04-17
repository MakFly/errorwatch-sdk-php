<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Tracing;

/**
 * Request-scoped, mutable trace context used by framework integrations
 * to correlate errors, logs and transactions across a request and
 * across services via W3C `traceparent` propagation.
 *
 * There is a separate value-object style `ErrorWatch\Laravel\Tracing\TraceContext`
 * for Laravel APM flows — this class is the shared, lifecycle-aware
 * holder consumed by Symfony and by the upgraded Laravel middleware.
 */
final class TraceContext
{
    private ?string $traceId = null;
    private ?string $currentSpanId = null;
    private ?string $parentSpanId = null;
    private bool $sampled = true;

    public function start(?string $traceId = null, ?string $parentSpanId = null, bool $sampled = true): void
    {
        $this->traceId = $traceId ?: self::generateTraceId();
        $this->parentSpanId = $parentSpanId;
        $this->currentSpanId = self::generateSpanId();
        $this->sampled = $sampled;
    }

    public function reset(): void
    {
        $this->traceId = null;
        $this->currentSpanId = null;
        $this->parentSpanId = null;
        $this->sampled = true;
    }

    public function hasContext(): bool
    {
        return null !== $this->traceId;
    }

    public function getTraceId(): ?string
    {
        return $this->traceId;
    }

    public function getCurrentSpanId(): ?string
    {
        return $this->currentSpanId;
    }

    public function getParentSpanId(): ?string
    {
        return $this->parentSpanId;
    }

    public function isSampled(): bool
    {
        return $this->sampled;
    }

    /**
     * Produces a fresh `traceparent` header value for an outbound request.
     * A new child span id is generated so the callee sees us as its parent.
     */
    public function generateOutboundTraceparent(): ?string
    {
        if (null === $this->traceId) {
            return null;
        }

        $childSpanId = self::generateSpanId();
        $flags = $this->sampled ? '01' : '00';

        return sprintf('00-%s-%s-%s', $this->traceId, $childSpanId, $flags);
    }

    /**
     * @return array{traceId: string, spanId: string, sampled: bool}|null
     */
    public static function parseTraceparent(?string $header): ?array
    {
        if (null === $header || '' === $header) {
            return null;
        }

        if (!preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/i', $header, $m)) {
            return null;
        }

        $traceId = strtolower($m[1]);
        $spanId = strtolower($m[2]);

        if (str_repeat('0', 32) === $traceId || str_repeat('0', 16) === $spanId) {
            return null;
        }

        return [
            'traceId' => $traceId,
            'spanId' => $spanId,
            'sampled' => (hexdec($m[3]) & 0x01) === 1,
        ];
    }

    public static function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
