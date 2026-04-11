<?php

namespace ErrorWatch\Symfony\Tests\Unit\Service;

use ErrorWatch\Symfony\Http\MonitoringClientInterface;
use ErrorWatch\Symfony\Service\DeprecationHandler;
use PHPUnit\Framework\TestCase;

final class DeprecationHandlerTest extends TestCase
{
    private MonitoringClientInterface $mockClient;
    /** @var callable|null */
    private $previousErrorHandler;

    protected function setUp(): void
    {
        $this->mockClient = $this->createMock(MonitoringClientInterface::class);
        $this->previousErrorHandler = set_error_handler(function () { return false; });
        restore_error_handler();
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    public function testHandleDeprecationSendsEvent(): void
    {
        $this->mockClient->expects($this->once())
            ->method('sendEventAsync')
            ->with($this->callback(function (array $payload): bool {
                return 'warning' === $payload['level']
                    && 'Function foo() is deprecated' === $payload['message']
                    && 'test' === $payload['env'];
            }));

        $handler = new DeprecationHandler(
            client: $this->mockClient,
            environment: 'test',
            release: '1.0.0',
        );

        $result = $handler->handleDeprecation(
            \E_USER_DEPRECATED,
            'Function foo() is deprecated',
            '/app/src/Foo.php',
            42,
        );

        $this->assertFalse($result);
    }

    public function testHandleDeprecationDeduplicates(): void
    {
        $this->mockClient->expects($this->once())
            ->method('sendEventAsync');

        $handler = new DeprecationHandler(
            client: $this->mockClient,
            environment: 'test',
        );

        $handler->handleDeprecation(\E_USER_DEPRECATED, 'Deprecated call', '/app/src/Bar.php', 10);
        $handler->handleDeprecation(\E_USER_DEPRECATED, 'Deprecated call', '/app/src/Bar.php', 10);
    }

    public function testHandleDeprecationReturnsFalse(): void
    {
        $this->mockClient->expects($this->any())
            ->method('sendEventAsync');

        $handler = new DeprecationHandler(
            client: $this->mockClient,
            environment: 'test',
        );

        $result = $handler->handleDeprecation(
            \E_USER_DEPRECATED,
            'Some deprecation',
            '/app/src/Baz.php',
            5,
        );

        $this->assertFalse($result);
    }

    public function testHandleDeprecationSendsDifferentEventsForDifferentErrors(): void
    {
        $this->mockClient->expects($this->exactly(2))
            ->method('sendEventAsync');

        $handler = new DeprecationHandler(
            client: $this->mockClient,
            environment: 'test',
        );

        $handler->handleDeprecation(\E_USER_DEPRECATED, 'First deprecation', '/app/src/A.php', 1);
        $handler->handleDeprecation(\E_USER_DEPRECATED, 'Second deprecation', '/app/src/B.php', 2);
    }

    public function testPayloadContainsRequiredFields(): void
    {
        $this->mockClient->expects($this->once())
            ->method('sendEventAsync')
            ->with($this->callback(function (array $payload): bool {
                return !empty($payload['message'])
                    && !empty($payload['file'])
                    && !empty($payload['line'])
                    && !empty($payload['stack'])
                    && isset($payload['env'])
                    && isset($payload['level'])
                    && isset($payload['url'])
                    && isset($payload['created_at']);
            }));

        $handler = new DeprecationHandler(
            client: $this->mockClient,
            environment: 'production',
            release: '2.0.0',
        );

        $handler->handleDeprecation(
            \E_DEPRECATED,
            'str_contains() is deprecated',
            '/app/vendor/lib/Helper.php',
            99,
        );
    }
}
