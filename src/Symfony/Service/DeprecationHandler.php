<?php

namespace ErrorWatch\Symfony\Service;

use ErrorWatch\Symfony\Http\MonitoringClientInterface;

final class DeprecationHandler
{
    /** @var array<string, true> */
    private array $seen = [];

    public function __construct(
        private readonly MonitoringClientInterface $client,
        private readonly ?string $environment,
        private readonly ?string $release = null,
    ) {
        set_error_handler([$this, 'handleDeprecation'], \E_DEPRECATED | \E_USER_DEPRECATED);
    }

    public function handleDeprecation(int $errno, string $message, string $file, int $line): bool
    {
        $key = md5($message . $file . $line);

        if (isset($this->seen[$key])) {
            return false;
        }

        $this->seen[$key] = true;

        $backtrace = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $stack = $this->formatBacktrace($backtrace);

        $payload = [
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'stack' => $stack,
            'env' => $this->environment,
            'url' => sprintf('deprecation://%s:%d', $file, $line),
            'level' => 'warning',
            'release' => $this->release,
            'created_at' => (int) (microtime(true) * 1000),
        ];

        $this->client->sendEventAsync($payload);

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $backtrace
     */
    private function formatBacktrace(array $backtrace): string
    {
        $lines = [];

        foreach ($backtrace as $i => $frame) {
            $file = $frame['file'] ?? 'unknown';
            $line = $frame['line'] ?? 0;
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';
            $function = $frame['function'] ?? '';

            $lines[] = sprintf('#%d %s(%d): %s%s%s()', $i, $file, $line, $class, $type, $function);
        }

        return implode("\n", $lines);
    }
}
