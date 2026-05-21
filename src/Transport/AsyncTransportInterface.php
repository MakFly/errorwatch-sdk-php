<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Transport;

/**
 * Optional async-capable transport contract.
 *
 * Kept separate from {@see TransportInterface} so that third-party transports
 * implementing only send() keep loading (sendAsync() is NOT a required method
 * of the base interface). Callers MUST check `instanceof AsyncTransportInterface`
 * before calling sendAsync() and fall back to send() otherwise.
 */
interface AsyncTransportInterface extends TransportInterface
{
    /**
     * Fire-and-forget variant of send().
     *
     * MUST return as fast as possible (no network I/O on the caller's
     * critical path). The actual delivery happens later — at process
     * shutdown, on kernel.terminate, or via a queue worker.
     *
     * MUST NOT throw.
     *
     * @param array $payload The serialised event array (from Event::toPayload())
     */
    public function sendAsync(array $payload): void;
}
