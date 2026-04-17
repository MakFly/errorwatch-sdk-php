<?php

namespace ErrorWatch\Symfony\Cache;

use ErrorWatch\Symfony\Model\Span;
use ErrorWatch\Symfony\Service\TransactionCollector;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Decorates the Symfony cache adapter to emit cache.get / cache.delete spans
 * with cache.key + cache.hit metadata attached to the current transaction.
 */
final class TraceableCacheAdapter implements CacheInterface, AdapterInterface
{
    public function __construct(
        private readonly CacheInterface&AdapterInterface $decorated,
        private readonly TransactionCollector $collector,
    ) {
    }

    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        $span = new Span('cache.get', $key);
        $span->setData('cache.key', $key);

        $hit = true;
        $tracer = static function (ItemInterface $item, bool &$save) use ($callback, &$hit) {
            $hit = false;

            return $callback($item, $save);
        };

        try {
            $value = $this->decorated->get($key, $tracer, $beta, $metadata);
            $span->setData('cache.hit', $hit);
            $span->setStatus('ok');

            return $value;
        } catch (\Throwable $e) {
            $span->setStatus('error');
            throw $e;
        } finally {
            $span->finish();
            $this->collector->addSpan($span);
        }
    }

    public function delete(string $key): bool
    {
        $span = new Span('cache.delete', $key);
        $span->setData('cache.key', $key);

        try {
            $result = $this->decorated->delete($key);
            $span->setStatus('ok');

            return $result;
        } catch (\Throwable $e) {
            $span->setStatus('error');
            throw $e;
        } finally {
            $span->finish();
            $this->collector->addSpan($span);
        }
    }

    public function getItem(mixed $key): CacheItem
    {
        $span = new Span('cache.get_item', (string) $key);
        $span->setData('cache.key', (string) $key);

        try {
            $item = $this->decorated->getItem($key);
            $span->setData('cache.hit', $item->isHit());
            $span->setStatus('ok');

            return $item;
        } catch (\Throwable $e) {
            $span->setStatus('error');
            throw $e;
        } finally {
            $span->finish();
            $this->collector->addSpan($span);
        }
    }

    public function getItems(array $keys = []): iterable
    {
        return $this->decorated->getItems($keys);
    }

    public function hasItem(string $key): bool
    {
        return $this->decorated->hasItem($key);
    }

    public function clear(string $prefix = ''): bool
    {
        return $this->decorated->clear($prefix);
    }

    public function deleteItem(mixed $key): bool
    {
        return $this->delete((string) $key);
    }

    public function deleteItems(array $keys): bool
    {
        return $this->decorated->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        $span = new Span('cache.set', $item->getKey());
        $span->setData('cache.key', $item->getKey());

        try {
            $result = $this->decorated->save($item);
            $span->setStatus($result ? 'ok' : 'error');

            return $result;
        } catch (\Throwable $e) {
            $span->setStatus('error');
            throw $e;
        } finally {
            $span->finish();
            $this->collector->addSpan($span);
        }
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        $span = new Span('cache.set', $item->getKey());
        $span->setData('cache.key', $item->getKey());
        $span->setData('cache.deferred', true);

        try {
            $result = $this->decorated->saveDeferred($item);
            $span->setStatus($result ? 'ok' : 'error');

            return $result;
        } catch (\Throwable $e) {
            $span->setStatus('error');
            throw $e;
        } finally {
            $span->finish();
            $this->collector->addSpan($span);
        }
    }

    public function commit(): bool
    {
        return $this->decorated->commit();
    }
}
