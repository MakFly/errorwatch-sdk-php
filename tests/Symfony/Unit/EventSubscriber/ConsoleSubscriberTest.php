<?php

namespace ErrorWatch\Symfony\Tests\Unit\EventSubscriber;

use ErrorWatch\Sdk\Client;
use ErrorWatch\Sdk\Options;
use ErrorWatch\Sdk\Transport\TransportInterface;
use ErrorWatch\Symfony\EventSubscriber\ConsoleSubscriber;
use ErrorWatch\Symfony\Service\BreadcrumbService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleSubscriberTest extends TestCase
{
    private Client $client;
    private TransportInterface $transport;
    private BreadcrumbService $breadcrumbService;
    private Command $command;
    private InputInterface $input;
    private OutputInterface $output;

    protected function setUp(): void
    {
        $this->transport = $this->createMock(TransportInterface::class);
        $this->client = new Client(
            new Options([
                'endpoint' => 'http://localhost',
                'api_key'  => 'test-key',
                'enabled'  => true,
            ]),
            $this->transport,
        );
        $this->breadcrumbService = new BreadcrumbService();

        $this->command = $this->createMock(Command::class);
        $this->command->method('getName')->willReturn('app:test-command');

        $this->input = $this->createMock(InputInterface::class);
        $this->output = $this->createMock(OutputInterface::class);
    }

    public function testOnCommandResetsFlagAndAddsBreadcrumb(): void
    {
        $subscriber = new ConsoleSubscriber($this->client, $this->breadcrumbService);

        $event = new ConsoleCommandEvent($this->command, $this->input, $this->output);
        $subscriber->onCommand($event);

        $this->assertSame(1, $this->breadcrumbService->count());
        $breadcrumbs = $this->breadcrumbService->all();
        $this->assertSame('console', $breadcrumbs[0]['category']);
        $this->assertStringContainsString('app:test-command', $breadcrumbs[0]['message']);
    }

    public function testOnErrorSendsErrorEvent(): void
    {
        $exception = new \RuntimeException('Something went wrong');

        $this->transport->expects($this->once())
            ->method('send')
            ->with($this->callback(function (array $payload) use ($exception): bool {
                return $payload['message'] === $exception->getMessage();
            }))
            ->willReturn(true);

        $subscriber = new ConsoleSubscriber($this->client, $this->breadcrumbService);

        $event = new ConsoleErrorEvent($this->input, $this->output, $exception, $this->command);
        $subscriber->onError($event);
    }

    public function testOnTerminateSendsWarningForNonZeroExitCode(): void
    {
        $this->transport->expects($this->once())
            ->method('send')
            ->with($this->callback(function (array $payload): bool {
                return str_contains($payload['message'] ?? '', 'app:test-command')
                    && str_contains($payload['message'] ?? '', 'code 1');
            }))
            ->willReturn(true);

        $subscriber = new ConsoleSubscriber($this->client, $this->breadcrumbService);

        $event = new ConsoleTerminateEvent($this->command, $this->input, $this->output, 1);
        $subscriber->onTerminate($event);
    }

    public function testOnTerminateDoesNothingForZeroExitCode(): void
    {
        $this->transport->expects($this->never())->method('send');

        $subscriber = new ConsoleSubscriber($this->client, $this->breadcrumbService);

        $event = new ConsoleTerminateEvent($this->command, $this->input, $this->output, 0);
        $subscriber->onTerminate($event);
    }

    public function testOnTerminateDoesNothingWhenErrorAlreadySent(): void
    {
        $exception = new \RuntimeException('Something went wrong');

        // send() is called once during onError, never during onTerminate
        $this->transport->expects($this->once())->method('send')->willReturn(true);

        $subscriber = new ConsoleSubscriber($this->client, $this->breadcrumbService);

        // First trigger onError to set the flag
        $errorEvent = new ConsoleErrorEvent($this->input, $this->output, $exception, $this->command);
        $subscriber->onError($errorEvent);

        // Then onTerminate should skip sending
        $terminateEvent = new ConsoleTerminateEvent($this->command, $this->input, $this->output, 1);
        $subscriber->onTerminate($terminateEvent);
    }

    public function testOnTerminateDoesNothingWhenCaptureExitCodesDisabled(): void
    {
        $this->transport->expects($this->never())->method('send');

        $subscriber = new ConsoleSubscriber(
            $this->client,
            $this->breadcrumbService,
            captureExitCodes: false,
        );

        $event = new ConsoleTerminateEvent($this->command, $this->input, $this->output, 1);
        $subscriber->onTerminate($event);
    }

    public function testGetSubscribedEventsReturnsCorrectEvents(): void
    {
        $events = ConsoleSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(ConsoleEvents::COMMAND, $events);
        $this->assertArrayHasKey(ConsoleEvents::ERROR, $events);
        $this->assertArrayHasKey(ConsoleEvents::TERMINATE, $events);
        $this->assertSame('onCommand', $events[ConsoleEvents::COMMAND]);
        $this->assertSame('onError', $events[ConsoleEvents::ERROR]);
        $this->assertSame('onTerminate', $events[ConsoleEvents::TERMINATE]);
    }
}
