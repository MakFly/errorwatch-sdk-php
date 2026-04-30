<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Logging;

use ErrorWatch\Laravel\Client\MonitoringClient;
use ErrorWatch\Laravel\Profiler\RequestProfile;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ErrorWatchExceptionHandler implements ExceptionHandler
{
    protected ExceptionHandler $handler;
    protected ErrorWatchLogger $logger;

    public function __construct(ExceptionHandler $handler, MonitoringClient $client, array $config)
    {
        $this->handler = $handler;
        $this->logger = new ErrorWatchLogger($client, $config);
    }

    public function report(Throwable $e): void
    {
        if ($this->shouldReport($e)) {
            $context = [];
            if ($e instanceof HttpExceptionInterface) {
                $context['status_code'] = $e->getStatusCode();
            }

            // Attach the per-request profile snapshot, if profiler is on.
            try {
                if (app('config')->get('errorwatch.profiler.enabled', false) && app()->bound(RequestProfile::class)) {
                    $profile = app(RequestProfile::class);
                    if ($profile->isStarted()) {
                        $context['profile'] = $profile->toArray($e, $context['status_code'] ?? null);
                    }
                }
            } catch (\Throwable) {
                // never let profiler crash the report path
            }

            $this->logger->handleException($e, $context);
        }

        $this->handler->report($e);
    }

    public function shouldReport(Throwable $e): bool
    {
        // Never report SDK-internal exceptions to avoid self-capture loops
        if ($this->isInternalException($e)) {
            return false;
        }

        return $this->handler->shouldReport($e);
    }

    protected function isInternalException(Throwable $e): bool
    {
        $class = get_class($e);
        if (str_starts_with($class, 'ErrorWatch\\Laravel\\') && !str_starts_with($class, 'ErrorWatch\\Laravel\\Tests\\')) {
            return true;
        }

        return str_contains($e->getFile(), 'vendor/errorwatch/sdk-laravel/src/');
    }

    public function render($request, Throwable $e)
    {
        return $this->handler->render($request, $e);
    }

    public function renderForConsole($output, Throwable $e)
    {
        return $this->handler->renderForConsole($output, $e);
    }
}
