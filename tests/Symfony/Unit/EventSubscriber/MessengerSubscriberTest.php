<?php

namespace ErrorWatch\Symfony\Tests\Unit\EventSubscriber;

use ErrorWatch\Sdk\Client;
use ErrorWatch\Sdk\Options;
use ErrorWatch\Sdk\Transport\TransportInterface;
use ErrorWatch\Symfony\EventSubscriber\MessengerSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final class MessengerSubscriberTest extends TestCase
{
    private function makeClient(TransportInterface $transport): Client
    {
        return new Client(
            new Options([
                'endpoint' => 'http://localhost',
                'api_key'  => 'test-key',
                'enabled'  => true,
            ]),
            $transport,
        );
    }

    public function testOnMessageFailedSendsErrorForFinalFailure(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $client = $this->makeClient($transport);
        $subscriber = new MessengerSubscriber($client);

        $message = new \stdClass();
        $throwable = new \RuntimeException('Job failed');
        $envelope = new Envelope($message);

        $event = new WorkerMessageFailedEvent($envelope, 'async', $throwable);

        $transport->expects($this->once())
            ->method('send')
            ->with($this->callback(function (array $payload) use ($throwable): bool {
                return $payload['message'] === $throwable->getMessage();
            }))
            ->willReturn(true);

        $subscriber->onMessageFailed($event);
    }

    public function testOnMessageFailedSkipsRetryWhenNotCapturing(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $client = $this->makeClient($transport);
        $subscriber = new MessengerSubscriber($client, captureRetries: false);

        $message = new \stdClass();
        $throwable = new \RuntimeException('Job failed');
        $envelope = new Envelope($message);

        $event = new WorkerMessageFailedEvent($envelope, 'async', $throwable);
        $event->setForRetry();

        $transport->expects($this->never())->method('send');

        $subscriber->onMessageFailed($event);
    }

    public function testOnMessageFailedSendsWarningForRetryWhenCapturing(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $client = $this->makeClient($transport);
        $subscriber = new MessengerSubscriber($client, captureRetries: true);

        $message = new \stdClass();
        $throwable = new \RuntimeException('Job failed, will retry');
        $envelope = new Envelope($message);

        $event = new WorkerMessageFailedEvent($envelope, 'async', $throwable);
        $event->setForRetry();

        $transport->expects($this->once())
            ->method('send')
            ->with($this->callback(function (array $payload) use ($throwable): bool {
                return $payload['message'] === $throwable->getMessage();
            }))
            ->willReturn(true);

        $subscriber->onMessageFailed($event);
    }

    public function testGetSubscribedEventsReturnsCorrectEvents(): void
    {
        $events = MessengerSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(WorkerMessageFailedEvent::class, $events);
        $this->assertSame('onMessageFailed', $events[WorkerMessageFailedEvent::class]);
    }
}
