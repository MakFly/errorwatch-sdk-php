<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Transport;

use ErrorWatch\Sdk\Transport\TransportInterface;

/**
 * A transport proxy that delegates send() to the MonitoringClient's own HttpTransport.
 *
 * This indirection means that when tests swap the HttpTransport on MonitoringClient
 * via reflection, the core SDK client automatically uses the new transport, because
 * it holds a reference to this delegate which reads the current transport from the
 * MonitoringClient on every call.
 */
final class TransportDelegate implements TransportInterface
{
    /** @var callable(): TransportInterface */
    private $resolver;

    /**
     * @param callable(): TransportInterface $resolver
     */
    public function __construct(callable $resolver)
    {
        $this->resolver = $resolver;
    }

    public function send(array $payload): bool
    {
        return ($this->resolver)()->send($payload);
    }
}
