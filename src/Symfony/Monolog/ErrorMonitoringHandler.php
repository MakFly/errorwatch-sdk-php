<?php

namespace ErrorWatch\Symfony\Monolog;

use ErrorWatch\Sdk\Exception\StacktraceBuilder;
use ErrorWatch\Sdk\Tracing\TraceContext;
use ErrorWatch\Symfony\Http\MonitoringClientInterface;
use ErrorWatch\Symfony\Service\FingerprintGenerator;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

final class ErrorMonitoringHandler extends AbstractProcessingHandler
{
    /**
     * @param array<int, string> $excludedChannels
     */
    public function __construct(
        private readonly MonitoringClientInterface $client,
        private readonly bool $enabled,
        private readonly ?string $environment,
        private readonly ?string $release,
        private readonly array $excludedChannels = ['event', 'doctrine', 'http_client'],
        private readonly bool $captureContext = true,
        private readonly bool $captureExtra = true,
        int|string|Level $level = Level::Warning,
        bool $bubble = true,
        private readonly ?TraceContext $traceContext = null,
        private readonly ?FingerprintGenerator $fingerprintGenerator = null,
        private readonly ?StacktraceBuilder $stacktraceBuilder = null,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (!$this->enabled || in_array($record->channel, $this->excludedChannels, true)) {
            return;
        }

        // When the record carries a Throwable, the ExceptionSubscriber already
        // reports it with v2 fingerprint + structured frames. Skipping here
        // avoids creating a duplicate error_group with a mismatched fingerprint.
        if (($record->context['exception'] ?? null) instanceof \Throwable) {
            return;
        }

        $context = $this->captureContext ? $this->normalize($record->context) : [];
        $extra = $this->captureExtra ? $this->normalize($record->extra) : [];

        // Sentry parity: Monolog records without a Throwable are live logs, not issues.
        $logPayload = [
            'level' => $this->mapLevel($record->level),
            'message' => $record->message,
            'channel' => $record->channel,
            'timestamp' => $record->datetime->getTimestamp() + ($record->datetime->format('u') / 1_000_000),
            'context' => $context ?: null,
            'extra' => $extra ?: null,
            'env' => $this->environment,
            'release' => $this->release,
        ];

        $statusCode = $this->extractStatusCode($context, $extra);
        if ($statusCode !== null) {
            $logPayload['status_code'] = $statusCode;
        }

        if (null !== $this->traceContext && $this->traceContext->hasContext()) {
            $logPayload['trace_id'] = $this->traceContext->getTraceId();
            $logPayload['span_id'] = $this->traceContext->getCurrentSpanId();
        }

        try {
            $this->client->sendLog($logPayload);
        } catch (\Throwable) {
            // Never break request lifecycle if remote logging fails.
        }
    }

    private function mapLevel(Level $level): string
    {
        return match ($level) {
            Level::Emergency, Level::Alert, Level::Critical => 'fatal',
            Level::Error => 'error',
            Level::Warning => 'warning',
            Level::Notice, Level::Info => 'info',
            Level::Debug => 'debug',
        };
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value) || null === $value) {
                $normalized[$key] = $value;
                continue;
            }

            if ($value instanceof \Stringable) {
                $normalized[$key] = (string) $value;
                continue;
            }

            if ($value instanceof \DateTimeInterface) {
                $normalized[$key] = $value->format(\DateTimeInterface::ATOM);
                continue;
            }

            if ($value instanceof \Throwable) {
                $normalized[$key] = [
                    'class' => $value::class,
                    'message' => $value->getMessage(),
                    'file' => $value->getFile(),
                    'line' => $value->getLine(),
                ];
                continue;
            }

            if (is_array($value)) {
                $normalized[$key] = $this->normalize($value);
                continue;
            }

            $normalized[$key] = get_debug_type($value);
        }

        return $normalized;
    }

    private function extractStatusCode(array $context, array $extra): ?int
    {
        $statusPaths = [
            ['status_code'],
            ['statusCode'],
            ['http_status_code'],
            ['http.status_code'],
            ['response_status_code'],
            ['response.status_code'],
            ['http', 'status_code'],
            ['response', 'status_code'],
            ['tags', 'status_code'],
            ['tags', 'statusCode'],
            ['tags', 'http.status_code'],
            ['tags', 'response.status_code'],
            ['tags', 'http_status_code'],
            ['tags', 'response_status_code'],
            ['context', 'status_code'],
            ['context', 'statusCode'],
            ['context', 'http.status_code'],
            ['context', 'response.status_code'],
            ['extra', 'status_code'],
            ['extra', 'statusCode'],
            ['extra', 'http.status_code'],
            ['extra', 'response.status_code'],
        ];

        $sources = [$context, $extra];
        foreach ($sources as $source) {
            foreach ($statusPaths as $path) {
                $value = $this->valueAtPath($source, $path);
                $statusCode = $this->coerceHttpStatusCode($value);
                if ($statusCode !== null) {
                    return $statusCode;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $subject
     * @param array<int, string> $path
     */
    private function valueAtPath(array $subject, array $path): mixed
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

    private function coerceHttpStatusCode(mixed $value): ?int
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
}
