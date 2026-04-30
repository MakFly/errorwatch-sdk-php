<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Services;

use ErrorWatch\Laravel\Client\MonitoringClient;
use ErrorWatch\Laravel\Profiler\RequestProfile;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;

/**
 * Captures sent mail messages into the per-request profile.
 */
final class MailListener
{
    public function __construct(
        private readonly MonitoringClient $client,
    ) {}

    public function register(): void
    {
        Event::listen(MessageSent::class, [$this, 'onMessageSent']);
    }

    public function onMessageSent(MessageSent $event): void
    {
        if (!$this->client->isEnabled() || !$this->client->getConfig('profiler.enabled', false)) {
            return;
        }
        try {
            $profile = app(RequestProfile::class);
            if (!$profile->isStarted()) {
                return;
            }

            $message = $event->message;
            $profile->recordMail([
                'to' => $this->addresses($message->getTo()),
                'from' => $this->addresses($message->getFrom()),
                'cc' => $this->addresses($message->getCc()),
                'bcc' => $this->addresses($message->getBcc()),
                'subject' => (string) $message->getSubject(),
                'body' => method_exists($message, 'getBody') ? (string) $message->getBody() : '',
                'attachments' => array_map(
                    static fn ($a) => method_exists($a, 'getFilename') ? (string) $a->getFilename() : 'attachment',
                    (array) $message->getAttachments(),
                ),
            ]);
        } catch (\Throwable) {
        }
    }

    /**
     * Normalize a Symfony Address[] (or null) into a flat array of "name <addr>" strings.
     */
    private function addresses(?array $addresses): array
    {
        if (!$addresses) {
            return [];
        }
        return array_map(
            static fn ($a) => method_exists($a, 'toString') ? (string) $a->toString() : (string) $a,
            $addresses,
        );
    }
}
