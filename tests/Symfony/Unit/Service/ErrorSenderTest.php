<?php

namespace ErrorWatch\Symfony\Tests\Unit\Service;

use ErrorWatch\Symfony\Http\MonitoringClientInterface;
use ErrorWatch\Symfony\Service\ErrorSender;
use ErrorWatch\Symfony\Service\LevelMapper;
use PHPUnit\Framework\TestCase;

final class ErrorSenderTest extends TestCase
{
    private MonitoringClientInterface $mockClient;
    private ErrorSender $sender;

    protected function setUp(): void
    {
        $this->mockClient = $this->createMock(MonitoringClientInterface::class);
        $this->sender = new ErrorSender(
            enabled: true,
            environment: 'test',
            release: null,
            client: $this->mockClient,
            levelMapper: new LevelMapper(),
        );
    }

    public function testSendsErrorToMonitoringServer(): void
    {
        $exception = new \RuntimeException('Test error message');

        $this->mockClient->expects($this->once())
            ->method('sendEventAsync')
            ->with($this->callback(function ($payload) {
                return isset($payload['message'])
                    && 'Test error message' === $payload['message']
                    && 'test' === $payload['env'];
            }));

        $this->sender->send($exception);
    }

    public function testSkipsWhenDisabled(): void
    {
        $senderDisabled = new ErrorSender(
            enabled: false,
            environment: 'test',
            release: null,
            client: $this->mockClient,
            levelMapper: new LevelMapper(),
        );

        $this->mockClient->expects($this->never())
            ->method('sendEventAsync');

        $senderDisabled->send(new \RuntimeException('Test'));
    }

    public function testCallsSendEventAsync(): void
    {
        $exception = new \RuntimeException('Test error');

        // Verify that sendEventAsync is called (error handling is in MonitoringClient)
        $this->mockClient->expects($this->once())
            ->method('sendEventAsync');

        $this->sender->send($exception);
    }

    public function testBuildsCorrectPayload(): void
    {
        $exception = new \RuntimeException('Test error');

        $this->mockClient->expects($this->once())
            ->method('sendEventAsync')
            ->with($this->callback(function ($payload) {
                return 'Test error' === $payload['message']
                    && 'test' === $payload['env']
                    && isset($payload['stack'])
                    && isset($payload['created_at']);
            }));

        $this->sender->send($exception, '/test-url');
    }

    public function testIncludesUrlInPayload(): void
    {
        $exception = new \RuntimeException('Test error');
        $url = '/test/endpoint';

        $this->mockClient->expects($this->once())
            ->method('sendEventAsync')
            ->with($this->callback(function ($payload) use ($url) {
                return $payload['url'] === $url;
            }));

        $this->sender->send($exception, $url);
    }

    public function testIncludesBreadcrumbsInPayload(): void
    {
        $exception = new \RuntimeException('Test error');

        $breadcrumbs = [
            ['category' => 'http', 'message' => 'GET /api/test'],
            ['category' => 'user', 'message' => 'Click submit'],
        ];

        $this->mockClient->expects($this->once())
            ->method('sendEventAsync')
            ->with($this->callback(function ($payload) use ($breadcrumbs) {
                return isset($payload['breadcrumbs'])
                    && $payload['breadcrumbs'] === $breadcrumbs;
            }));

        $this->sender->send($exception, '/test', null, null, ['breadcrumbs' => $breadcrumbs]);
    }

    public function testIncludesUserContextInPayload(): void
    {
        $exception = new \RuntimeException('Test error');

        $userContext = [
            'id' => 'user123',
            'email' => 'test@example.com',
            'ip_address' => '192.168.1.1',
        ];

        $this->mockClient->expects($this->once())
            ->method('sendEventAsync')
            ->with($this->callback(function ($payload) use ($userContext) {
                return isset($payload['user'])
                    && $payload['user'] === $userContext;
            }));

        $this->sender->send($exception, '/test', null, null, ['user' => $userContext]);
    }

    public function testIncludesFullContextInPayload(): void
    {
        $exception = new \RuntimeException('Test error');

        $context = [
            'breadcrumbs' => [['category' => 'http', 'message' => 'GET /api/test']],
            'user' => ['id' => 'user123'],
            'request' => ['method' => 'POST', 'url' => '/api/submit'],
        ];

        $this->mockClient->expects($this->once())
            ->method('sendEventAsync')
            ->with($this->callback(function ($payload) use ($context) {
                return $payload['breadcrumbs'] === $context['breadcrumbs']
                    && $payload['user'] === $context['user']
                    && $payload['request'] === $context['request'];
            }));

        $this->sender->send($exception, '/test', null, null, $context);
    }

    public function testEmptyContextDoesNotAddFields(): void
    {
        $exception = new \RuntimeException('Test error');

        $this->mockClient->expects($this->once())
            ->method('sendEventAsync')
            ->with($this->callback(function ($payload) {
                return !isset($payload['breadcrumbs'])
                    && !isset($payload['user'])
                    && !isset($payload['request']);
            }));

        $this->sender->send($exception, '/test', null, null, []);
    }
}
