<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Jobs;

use ErrorWatch\Laravel\Client\MonitoringClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Background delivery of an ErrorWatch envelope. Used when
 * `transport.mode` resolves to `queue` so the host request lifecycle
 * spends zero time on HTTP I/O — the worker (Redis/database queue)
 * picks up the payload and POSTs it synchronously with full retry
 * logic on the worker side.
 */
final class SendEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var 'event'|'transaction'|'log' */
    public string $kind;

    /** @var array<string, mixed> */
    public array $payload;

    public ?string $env;

    /** Avoid the SDK SDK-poisoning the worker on persistent failure. */
    public int $tries = 3;
    public int $backoff = 5;
    public int $timeout = 15;

    public function __construct(string $kind, array $payload, ?string $env = null)
    {
        $this->kind    = $kind;
        $this->payload = $payload;
        $this->env     = $env;
    }

    public function handle(MonitoringClient $client): void
    {
        $transport = $client->getTransport();

        match ($this->kind) {
            'event'       => $transport->send($this->payload),
            'transaction' => $transport->sendTransaction($this->payload, $this->env),
            'log'         => $transport->sendLog($this->payload),
            default       => null,
        };
    }

    public function failed(\Throwable $e): void
    {
        // Swallow — the SDK must never break the host application even
        // when a worker permanently fails to deliver an event.
        error_log('[ErrorWatch] SendEventJob permanently failed: ' . $e->getMessage());
    }
}
