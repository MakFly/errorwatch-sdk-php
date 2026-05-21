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

    // NOTE: async delivery is opt-in via the separate AsyncTransportInterface.
    // It is intentionally NOT declared here so third-party transports written
    // against the original send()-only contract keep loading.
}
