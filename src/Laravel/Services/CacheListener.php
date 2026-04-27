<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Services;

use ErrorWatch\Laravel\Client\MonitoringClient;
use ErrorWatch\Laravel\Tracing\Span;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Event;

/**
 * Emits APM spans for Laravel Cache operations.
 *
 * Spans:
 *   - cache.get_item       (CacheHit + CacheMissed)
 *   - cache.put_item       (KeyWritten)
 *   - cache.remove_item    (KeyForgotten)
 *
 * Each span carries the cache key, store, and hit/miss flag as tags so
 * the dashboard's Cache card can compute hit ratio + slowest stores.
 *
 * Cache events are fired synchronously; we close each span immediately
 * because Laravel does not give us a duration. The span duration is
 * therefore the hop time inside this handler — not the cache call itself.
 * This is consistent with how QueryListener uses `finishWithDuration`
 * for SQL, but Laravel's cache events do not expose the call duration.
 */
class CacheListener
{
    /** @var array<int, float> Pending op start times keyed by spl_object_id($event) */
    private array $pendingStarts = [];

    public function __construct(
        protected MonitoringClient $client,
    ) {}

    public function register(): void
    {
        Event::listen(CacheHit::class,    [$this, 'onHit']);
        Event::listen(CacheMissed::class, [$this, 'onMissed']);
        Event::listen(KeyWritten::class,  [$this, 'onWritten']);
        Event::listen(KeyForgotten::class, [$this, 'onForgotten']);
    }

    public function onHit(CacheHit $event): void
    {
        $this->emit('cache.get_item', $event->key, $event->storeName ?? 'default', [
            'cache.hit' => true,
        ]);
    }

    public function onMissed(CacheMissed $event): void
    {
        $this->emit('cache.get_item', $event->key, $event->storeName ?? 'default', [
            'cache.hit' => false,
        ]);
    }

    public function onWritten(KeyWritten $event): void
    {
        $this->emit('cache.put_item', $event->key, $event->storeName ?? 'default', [
            'cache.ttl_seconds' => $event->seconds ?? null,
        ]);
    }

    public function onForgotten(KeyForgotten $event): void
    {
        $this->emit('cache.remove_item', $event->key, $event->storeName ?? 'default', []);
    }

    /**
     * @param array<string, scalar|null> $extraTags
     */
    private function emit(string $op, string $key, string $store, array $extraTags): void
    {
        if (!$this->client->isEnabled()) {
            return;
        }

        if (!$this->client->getConfig('apm.enabled', true)) {
            return;
        }

        if (!$this->client->getConfig('apm.cache.enabled', true)) {
            return;
        }

        $transaction = $this->client->getCurrentTransaction();
        if (!$transaction instanceof Span) {
            return;
        }

        $span = $transaction->startChild(
            $key,
            $op,
        );

        $span->setTag('cache.key', $this->truncate($key, 200));
        $span->setTag('cache.store', $store);

        foreach ($extraTags as $k => $v) {
            if ($v !== null) {
                $span->setTag($k, $v);
            }
        }

        $span->setOk();
        $span->finish();
    }

    private function truncate(string $value, int $max): string
    {
        return strlen($value) > $max ? substr($value, 0, $max) . '…' : $value;
    }
}
