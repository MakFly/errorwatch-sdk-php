<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel;

use ErrorWatch\Laravel\Client\MonitoringClient;
use ErrorWatch\Laravel\Commands\InstallCommand;
use ErrorWatch\Laravel\Commands\TestCommand;
use ErrorWatch\Laravel\Http\Middleware\ErrorWatchMiddleware;
use ErrorWatch\Laravel\Logging\ErrorWatchLogger;
use ErrorWatch\Laravel\Logging\ErrorWatchExceptionHandler;
use ErrorWatch\Laravel\Services\DeprecationHandler;
use ErrorWatch\Laravel\Services\HttpClientListener;
use ErrorWatch\Laravel\Services\QueryListener;
use ErrorWatch\Laravel\Services\QueueListener;
use ErrorWatch\Laravel\Listeners\EventSubscriber;
use ErrorWatch\Sdk\Client as SdkClient;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class ErrorWatchServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/config/errorwatch.php',
            'errorwatch'
        );

        // Register MonitoringClient as singleton
        $this->app->singleton(MonitoringClient::class, function ($app) {
            return new MonitoringClient($app['config']->get('errorwatch'));
        });

        // Expose the core SDK client (delegate to MonitoringClient's internal instance)
        $this->app->singleton(SdkClient::class, function ($app) {
            return $app->make(MonitoringClient::class)->getSdkClient();
        });

        // Register facade accessor
        $this->app->alias(MonitoringClient::class, 'errorwatch');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__ . '/config/errorwatch.php' => config_path('errorwatch.php'),
        ], 'errorwatch-config');

        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                TestCommand::class,
            ]);
        }

        // Only register integrations if enabled
        if (!$this->app['config']->get('errorwatch.enabled', true)) {
            return;
        }

        // Register HTTP middleware
        $this->registerMiddleware();

        // Register event subscribers
        $this->registerEventSubscribers();

        // Register query listener for Eloquent
        $this->registerQueryListener();

        // Register queue listener
        $this->registerQueueListener();

        // Register HTTP client listener
        $this->registerHttpClientListener();

        // Register deprecation handler
        $this->registerDeprecationHandler();

        // Register log listener (Laravel-native)
        $this->registerLogListener();

        // Register exception handler extension
        $this->registerExceptionHandler();

        // Register Blade directive for session replay
        $this->registerBladeDirective();

        // Reset state between requests for long-running servers (Octane, RoadRunner)
        $this->registerOctaneReset();
    }

    /**
     * Register the HTTP middleware.
     */
    protected function registerMiddleware(): void
    {
        if ($this->app->bound(Kernel::class)) {
            $kernel = $this->app->make(Kernel::class);

            if (method_exists($kernel, 'pushMiddleware')) {
                $kernel->pushMiddleware(ErrorWatchMiddleware::class);
            }
        }
    }

    /**
     * Register event subscribers.
     */
    protected function registerEventSubscribers(): void
    {
        // EventSubscriber::subscribe() handles per-feature config checks internally
        if ($this->app['config']->get('errorwatch.security.enabled', true) ||
            $this->app['config']->get('errorwatch.console.enabled', true)) {
            Event::subscribe(EventSubscriber::class);
        }
    }

    /**
     * Register query listener for Eloquent.
     */
    protected function registerQueryListener(): void
    {
        if ($this->app['config']->get('errorwatch.apm.eloquent.enabled', true)) {
            $this->app->make(QueryListener::class)->register();
        }
    }

    /**
     * Register queue listener.
     */
    protected function registerQueueListener(): void
    {
        if ($this->app['config']->get('errorwatch.queue.enabled', true)) {
            $this->app->make(QueueListener::class)->register();
        }
    }

    /**
     * Register HTTP client listener.
     */
    protected function registerHttpClientListener(): void
    {
        if ($this->app['config']->get('errorwatch.apm.http_client.enabled', true)) {
            $this->app->make(HttpClientListener::class)->register();
        }
    }

    /**
     * Register PHP deprecation handler.
     */
    protected function registerDeprecationHandler(): void
    {
        if ($this->app['config']->get('errorwatch.deprecations.enabled', false)) {
            $this->app->singleton(DeprecationHandler::class, function ($app) {
                return new DeprecationHandler(
                    $app->make(MonitoringClient::class)
                );
            });

            $this->app->make(DeprecationHandler::class);
        }
    }

    /**
     * Register log listener (Laravel-native, replaces Monolog handler).
     */
    protected function registerLogListener(): void
    {
        if (!$this->app['config']->get('errorwatch.logging.enabled', true)) {
            return;
        }

        $this->app['events']->listen(MessageLogged::class, function (MessageLogged $event) {
            // Prevent self-capture: skip logs originating from the SDK itself
            if (str_contains($event->message, '[ErrorWatch]')) {
                return;
            }

            $excludedChannels = $this->app['config']->get('errorwatch.logging.excluded_channels', []);

            $channel = property_exists($event, 'channel') ? $event->channel : 'application';

            if (in_array($channel, $excludedChannels, true)) {
                return;
            }

            $minLevel = $this->app['config']->get('errorwatch.logging.level', 'error');
            if (!$this->shouldLog($event->level, $minLevel)) {
                return;
            }

            $client = $this->app->make(MonitoringClient::class);
            $logger = new ErrorWatchLogger($client, $this->app['config']->get('errorwatch'));

            $logger->handleLog($event->level, $event->message, [
                'channel' => $channel,
                'context' => $event->context,
            ]);
        });
    }

    /**
     * Check if a log level should be captured.
     */
    protected function shouldLog(string $level, string $minLevel): bool
    {
        $levelMap = [
            'debug' => 100,
            'info' => 200,
            'notice' => 250,
            'warning' => 300,
            'error' => 400,
            'critical' => 500,
            'alert' => 550,
            'emergency' => 600,
        ];

        $levelValue = $levelMap[$level] ?? 400;
        $minLevelValue = $levelMap[$minLevel] ?? 400;

        return $levelValue >= $minLevelValue;
    }

    /**
     * Register exception handler extension.
     */
    protected function registerExceptionHandler(): void
    {
        if (!$this->app['config']->get('errorwatch.exceptions.enabled', true)) {
            return;
        }

        $this->app->extend(\Illuminate\Contracts\Debug\ExceptionHandler::class, function ($handler, $app) {
            return new ErrorWatchExceptionHandler(
                $handler,
                $app->make(MonitoringClient::class),
                $app['config']->get('errorwatch')
            );
        });
    }

    /**
     * Register Blade directive for session replay.
     */
    protected function registerBladeDirective(): void
    {
        Blade::directive('errorwatchReplay', function () {
            $replayEnabled = $this->app['config']->get('errorwatch.replay.enabled', false);

            if (!$replayEnabled) {
                return '';
            }

            return "<?php
            \$__ewSampleRate = (float) config('errorwatch.replay.sample_rate', 0.1);
            \$__ewEndpoint = htmlspecialchars(config('errorwatch.endpoint', ''), ENT_QUOTES, 'UTF-8');
            \$__ewApiKey = htmlspecialchars(config('errorwatch.api_key', ''), ENT_QUOTES, 'UTF-8');
            if (mt_rand(1, 100) <= (\$__ewSampleRate * 100)) {
                echo '<script src=\"' . \$__ewEndpoint . '/replay.js\" data-api-key=\"' . \$__ewApiKey . '\"></script>';
            }
        ?>";
        });
    }

    /**
     * Register Octane reset listener to clear per-request state.
     */
    protected function registerOctaneReset(): void
    {
        if (!class_exists(\Laravel\Octane\Events\RequestReceived::class)) {
            return;
        }

        $this->app['events']->listen(\Laravel\Octane\Events\RequestReceived::class, function () {
            if ($this->app->bound(MonitoringClient::class)) {
                $client = $this->app->make(MonitoringClient::class);
                $client->getBreadcrumbManager()->clear();
                $client->clearUser();
                $client->getTransport()->resetState();

                if (method_exists($client, 'clearCapturedExceptions')) {
                    $client->clearCapturedExceptions();
                }
            }
        });
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            MonitoringClient::class,
            SdkClient::class,
            'errorwatch',
            DeprecationHandler::class,
        ];
    }
}
