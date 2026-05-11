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

    /**
     * Fire-and-forget variant of send().
     *
     * MUST return as fast as possible (no network I/O on the caller's
     * critical path). The actual delivery happens later — at process
     * shutdown, on kernel.terminate, or via a queue worker.
     *
     * Transports that cannot honour the async contract MAY fall back
     * to send() internally, but callers should treat this as best-effort.
     *
     * MUST NOT throw.
     */
    public function sendAsync(array $payload): void;
}
