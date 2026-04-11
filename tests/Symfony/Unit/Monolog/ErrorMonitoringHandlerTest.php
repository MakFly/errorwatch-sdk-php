<?php

namespace ErrorWatch\Symfony\Tests\Unit\Monolog;

use ErrorWatch\Symfony\Http\MonitoringClientInterface;
use ErrorWatch\Symfony\Monolog\ErrorMonitoringHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ErrorMonitoringHandlerTest extends TestCase
{
    private MonitoringClientInterface&MockObject $client;

    protected function setUp(): void
    {
        $this->client = $this->createMock(MonitoringClientInterface::class);
    }

    public function testForwardsWarningLogByDefault(): void
    {
        $handler = new ErrorMonitoringHandler(
            client: $this->client,
            enabled: true,
            environment: 'dev',
            release: '1.2.3'
        );

        $this->client
            ->expects($this->once())
            ->method('sendEventAsync')
            ->with($this->callback(static function (array $payload): bool {
                return 'warning test' === $payload['message']
                    && 'warning' === $payload['level']
                    && 'dev' === $payload['env']
                    && '1.2.3' === $payload['release'];
            }));

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Warning,
            message: 'warning test',
            context: ['foo' => 'bar'],
            extra: []
        );

        $handler->handle($record);
    }

    public function testIgnoresExcludedChannel(): void
    {
        $handler = new ErrorMonitoringHandler(
            client: $this->client,
            enabled: true,
            environment: 'dev',
            release: null,
            excludedChannels: ['http_client']
        );

        $this->client->expects($this->never())->method('sendEventAsync');

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'http_client',
            level: Level::Error,
            message: 'network failure',
            context: [],
            extra: []
        );

        $handler->handle($record);
    }

    public function testExtractsExceptionMetadataFromContext(): void
    {
        $handler = new ErrorMonitoringHandler(
            client: $this->client,
            enabled: true,
            environment: 'dev',
            release: null
        );

        $exception = new \RuntimeException('boom');

        $this->client
            ->expects($this->once())
            ->method('sendEventAsync')
            ->with($this->callback(static function (array $payload): bool {
                return 'boom' === $payload['message']
                    && is_string($payload['file'])
                    && is_int($payload['line'])
                    && is_string($payload['stack']);
            }));

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Error,
            message: 'caught error',
            context: ['exception' => $exception],
            extra: []
        );

        $handler->handle($record);
    }
}
