<?php

namespace ErrorWatch\Symfony\EventSubscriber;

use ErrorWatch\Sdk\Client;
use ErrorWatch\Sdk\Scope;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final class MessengerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Client $client,
        private readonly bool $captureRetries = false,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class => 'onMessageFailed',
        ];
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if (!$this->captureRetries && $event->willRetry()) {
            return;
        }

        $envelope = $event->getEnvelope();
        $throwable = $event->getThrowable();
        $messageClass = get_class($envelope->getMessage());
        $url = sprintf('messenger://%s', $messageClass);

        $scope = new Scope();
        $scope->setRequest(['url' => $url]);
        $scope->setTag('messenger.will_retry', $event->willRetry() ? 'true' : 'false');

        $this->client->captureException($throwable, $scope);
    }
}
