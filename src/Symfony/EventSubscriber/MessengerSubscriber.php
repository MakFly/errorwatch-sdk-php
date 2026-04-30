<?php

namespace ErrorWatch\Symfony\EventSubscriber;

use ErrorWatch\Sdk\Client;
use ErrorWatch\Sdk\Scope;
use ErrorWatch\Symfony\Profiler\RequestProfile;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

final class MessengerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Client $client,
        private readonly bool $captureRetries = false,
        private readonly ?RequestProfile $profile = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class => 'onMessageFailed',
            WorkerMessageHandledEvent::class => 'onMessageHandled',
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

        if ($this->profile !== null && $this->profile->isStarted()) {
            $this->profile->recordJob($event->getReceiverName(), $messageClass, 'failed', 0.0);
        }
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        if ($this->profile !== null && $this->profile->isStarted()) {
            $messageClass = get_class($event->getEnvelope()->getMessage());
            $this->profile->recordJob($event->getReceiverName(), $messageClass, 'processed', 0.0);
        }
    }
}
