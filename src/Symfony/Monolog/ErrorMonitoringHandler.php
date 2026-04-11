<?php

namespace ErrorWatch\Symfony\Monolog;

use ErrorWatch\Symfony\Http\MonitoringClientInterface;
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

        $message = $record->message;
        $file = 'monolog';
        $line = 1;
        $stack = $record->channel.'.'.$record->level->name.': '.$record->message;

        if (($record->context['exception'] ?? null) instanceof \Throwable) {
            /** @var \Throwable $exception */
            $exception = $record->context['exception'];
            $message = '' !== $exception->getMessage() ? $exception->getMessage() : $message;
            $file = $exception->getFile();
            $line = $exception->getLine();
            $stack = $exception->getTraceAsString();
        }

        $payload = [
            'message' => $message,
            'file' => $file,
            'line' => max(1, $line),
            'stack' => $stack,
            'env' => $this->environment,
            'level' => $this->mapLevel($record->level),
            'release' => $this->release,
            'created_at' => (int) $record->datetime->format('Uv'),
        ];

        if (!empty($context) || !empty($extra)) {
            $payload['context'] = array_filter([
                'channel' => $record->channel,
                'context' => $context ?: null,
                'extra' => $extra ?: null,
            ]);
        }

        try {
            $this->client->sendEventAsync($payload);
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
}
