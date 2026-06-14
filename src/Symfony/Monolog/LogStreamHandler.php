<?php

namespace ErrorWatch\Symfony\Monolog;

use ErrorWatch\Sdk\Tracing\TraceContext;
use ErrorWatch\Symfony\Http\MonitoringClientInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

final class LogStreamHandler extends AbstractProcessingHandler
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
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        private readonly ?TraceContext $traceContext = null,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (!$this->enabled || in_array($record->channel, $this->excludedChannels, true)) {
            return;
        }

        $context = $this->captureContext ? $this->normalize($record->context) : [];
        $extra = $this->captureExtra ? $this->normalize($record->extra) : [];

        $source = 'app';
        if ('messenger' === $record->channel) {
            $source = 'messenger';
        } elseif ('deprecation' === $record->channel) {
            $source = 'deprecation';
        }

        $payload = [
            'timestamp' => (int) $record->datetime->format('Uv'),
            'level' => $this->mapLevel($record->level),
            'channel' => $record->channel,
            'message' => $record->message,
            'env' => $this->environment,
            'release' => $this->release,
            'source' => $source,
            'url' => $record->context['url'] ?? null,
            'request_id' => $record->extra['request_id'] ?? $record->context['request_id'] ?? null,
            'user_id' => $record->context['user_id'] ?? null,
        ];

        // Skip empty context/extra so json_encode produces `{}` instead of
        // `[]`, which would fail the API's Record<string, unknown> Zod schema.
        if (!empty($context)) {
            $payload['context'] = $context;
        }
        if (!empty($extra)) {
            $payload['extra'] = $extra;
        }

        $statusCode = $this->extractStatusCode($context, $extra);
        if ($statusCode !== null) {
            $payload['status_code'] = $statusCode;
        }

        if (null !== $this->traceContext && $this->traceContext->hasContext()) {
            $payload['trace_id'] = $this->traceContext->getTraceId();
            $payload['span_id'] = $this->traceContext->getCurrentSpanId();
        }

        try {
            $this->client->sendLog($payload);
        } catch (\Throwable) {
            // Never break request lifecycle if remote logging fails.
        }
    }

    private function mapLevel(Level $level): string
    {
        return match ($level) {
            Level::Emergency, Level::Alert, Level::Critical, Level::Error => 'error',
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
