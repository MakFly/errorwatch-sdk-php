<?php

declare(strict_types=1);

namespace ErrorWatch\Symfony\EventSubscriber;

use ErrorWatch\Symfony\Profiler\RequestProfile;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mime\Email;

/**
 * Captures sent mail messages into the per-request profile.
 *
 * Listens to {@see SentMessageEvent} which Symfony Mailer dispatches once a
 * message has been delivered to the configured transport.
 */
final class MailSubscriber implements EventSubscriberInterface
{
    private readonly bool $enabled;

    public function __construct(
        private readonly RequestProfile $profile,
        mixed $enabled,
    ) {
        $this->enabled = (bool) filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
    }

    public static function getSubscribedEvents(): array
    {
        if (!class_exists(SentMessageEvent::class)) {
            return [];
        }
        return [SentMessageEvent::class => 'onSent'];
    }

    public function onSent(SentMessageEvent $event): void
    {
        if (!$this->enabled || !$this->profile->isStarted()) {
            return;
        }

        try {
            $message = $event->getMessage();
            if (!$message instanceof Email) {
                return;
            }

            $this->profile->recordMail([
                'to' => $this->addresses($message->getTo()),
                'from' => $this->addresses($message->getFrom()),
                'cc' => $this->addresses($message->getCc()),
                'bcc' => $this->addresses($message->getBcc()),
                'subject' => (string) $message->getSubject(),
                'body' => (string) ($message->getTextBody() ?? $message->getHtmlBody() ?? ''),
                'attachments' => array_map(
                    static fn ($a) => method_exists($a, 'getFilename') ? (string) $a->getFilename() : 'attachment',
                    (array) $message->getAttachments(),
                ),
            ]);
        } catch (\Throwable) {
            // Never break the request from inside the profiler.
        }
    }

    /**
     * @param array<int, mixed> $addresses
     * @return array<int, string>
     */
    private function addresses(array $addresses): array
    {
        return array_map(
            static fn ($a) => method_exists($a, 'toString') ? (string) $a->toString() : (string) $a,
            $addresses,
        );
    }
}
