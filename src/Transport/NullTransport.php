<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Transport;

/**
 * No-op transport — used when the SDK is disabled or in testing.
 */
class NullTransport implements TransportInterface
{
    public function send(array $payload): bool
    {
        return true;
    }
}
