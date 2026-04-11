<?php

namespace ErrorWatch\Symfony\Monolog;

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
            'context' => $context,
            'extra' => $extra,
            'env' => $this->environment,
            'release' => $this->release,
            'source' => $source,
            'url' => $record->context['url'] ?? null,
            'request_id' => $record->extra['request_id'] ?? $record->context['request_id'] ?? null,
            'user_id' => $record->context['user_id'] ?? null,
        ];

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
}
