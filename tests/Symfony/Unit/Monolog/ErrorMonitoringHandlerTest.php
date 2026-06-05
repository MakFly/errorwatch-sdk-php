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
            ->method('sendLog')
            ->with($this->callback(static function (array $payload): bool {
                return 'warning test' === $payload['message']
                    && 'warning' === $payload['level']
                    && 'app' === $payload['channel']
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

        $this->client->expects($this->never())->method('sendLog');

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

    public function testSkipsRecordCarryingThrowableToAvoidDuplicate(): void
    {
        // The Monolog handler intentionally skips records that carry a
        // Throwable in their context — those are already captured by the
        // ExceptionSubscriber with the canonical v2 fingerprint + frames.
        // Re-emitting from here would produce a duplicate error_group
        // with a mismatched fingerprint.
        $handler = new ErrorMonitoringHandler(
            client: $this->client,
            enabled: true,
            environment: 'dev',
            release: null
        );

        $this->client->expects($this->never())->method('sendLog');

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Error,
            message: 'caught error',
            context: ['exception' => new \RuntimeException('boom')],
            extra: []
        );

        $handler->handle($record);
    }
}
