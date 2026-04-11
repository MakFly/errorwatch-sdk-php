<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Transport;

interface TransportInterface
{
    /**
     * Send an event payload to the monitoring backend.
     *
     * MUST NOT throw — silent failures are expected behaviour so that
     * the monitoring SDK never crashes the host application.
     *
     * @param array $payload The serialised event array (from Event::toPayload())
     * @return bool           true on success, false on any failure
     */
    public function send(array $payload): bool;
}
