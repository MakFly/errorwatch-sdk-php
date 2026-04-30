<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Services;

use ErrorWatch\Laravel\Client\MonitoringClient;
use ErrorWatch\Laravel\Profiler\RequestProfile;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Captures every dispatched application event into the per-request profile.
 *
 * Listens via the wildcard subscriber `*` so we observe both string events and
 * dispatched event classes.
 */
final class EventCollectorListener
{
    private const IGNORED_PREFIXES = [
        'Illuminate\\Cache\\Events\\',
        'Illuminate\\Database\\Events\\',
        'Illuminate\\Queue\\Events\\',
        'Illuminate\\Http\\Client\\Events\\',
        'Illuminate\\Log\\Events\\',
        'Illuminate\\Mail\\Events\\',
        'Illuminate\\View\\Events\\',
        'Illuminate\\Auth\\Events\\',
        'Illuminate\\Console\\Events\\',
    ];

    public function __construct(
        private readonly MonitoringClient $client,
    ) {}

    public function register(): void
    {
        $dispatcher = app(Dispatcher::class);
        $dispatcher->listen('*', function (string $eventName, array $payload) use ($dispatcher) {
            $this->record($eventName, $dispatcher);
        });
    }

    private function record(string $eventName, Dispatcher $dispatcher): void
    {
        if (!$this->client->isEnabled() || !$this->client->getConfig('profiler.enabled', false)) {
            return;
        }

        // Skip events already covered by dedicated collectors to keep the bag small.
        foreach (self::IGNORED_PREFIXES as $prefix) {
            if (str_starts_with($eventName, $prefix)) {
                return;
            }
        }

        try {
            $profile = app(RequestProfile::class);
            if (!$profile->isStarted()) {
                return;
            }
            $listenerCount = method_exists($dispatcher, 'getListeners')
                ? count($dispatcher->getListeners($eventName))
                : 0;
            $profile->recordEvent($eventName, $listenerCount);
        } catch (\Throwable) {
        }
    }
}
