<?php

namespace ErrorWatch\Laravel\Tests\Unit;

use ErrorWatch\Laravel\Client\MonitoringClient;
use ErrorWatch\Laravel\Tests\TestCase;
use ErrorWatch\Laravel\Facades\ErrorWatch;
use ErrorWatch\Laravel\Transport\HttpTransport;
use Mockery;
use RuntimeException;
use PHPUnit\Framework\Attributes\Test;

class MonitoringClientTest extends TestCase
{
    #[Test]
    public function it_can_check_if_enabled(): void
    {
        $this->assertTrue(ErrorWatch::isEnabled());

        // Test with a new client instance with disabled config
        $disabledClient = new MonitoringClient([
            'enabled' => false,
            'endpoint' => 'https://test.errorwatch.io',
            'api_key' => 'test-key',
        ]);

        $this->assertFalse($disabledClient->isEnabled());
    }

    #[Test]
    public function it_can_get_config(): void
    {
        $this->assertEquals('testing', ErrorWatch::getConfig('environment'));
        $this->assertEquals('default', ErrorWatch::getConfig('nonexistent', 'default'));
    }

    #[Test]
    public function it_can_sample_events(): void
    {
        // Test with 100% sample rate
        $this->assertTrue(ErrorWatch::shouldSample(1.0));

        // Test with 0% sample rate
        $this->assertFalse(ErrorWatch::shouldSample(0.0));
    }

    #[Test]
    public function it_can_set_and_get_user(): void
    {
        $user = [
            'id' => 123,
            'email' => 'test@example.com',
            'username' => 'testuser',
        ];

        ErrorWatch::setUser($user);

        $this->assertEquals($user['id'], ErrorWatch::getUser()['id']);
        $this->assertEquals($user['email'], ErrorWatch::getUser()['email']);
    }

    #[Test]
    public function it_can_clear_user(): void
    {
        ErrorWatch::setUser(['id' => 123]);
        ErrorWatch::clearUser();

        $this->assertNull(ErrorWatch::getUser());
    }

    #[Test]
    public function it_can_add_breadcrumbs(): void
    {
        ErrorWatch::addBreadcrumb('Test breadcrumb', 'info', ['key' => 'value']);

        $breadcrumbs = ErrorWatch::getBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('Test breadcrumb', $breadcrumbs[0]['message']);
    }

    #[Test]
    public function it_can_clear_breadcrumbs(): void
    {
        ErrorWatch::addBreadcrumb('Test 1');
        ErrorWatch::addBreadcrumb('Test 2');

        ErrorWatch::clearBreadcrumbs();

        $this->assertEmpty(ErrorWatch::getBreadcrumbs());
    }

    #[Test]
    public function it_can_start_and_finish_transaction(): void
    {
        $transaction = ErrorWatch::startTransaction('test-transaction');

        $this->assertNotNull($transaction);
        $this->assertEquals('test-transaction', $transaction->toArray()['name']);

        $result = ErrorWatch::finishTransaction();

        $this->assertIsArray($result);
        $this->assertEquals('test-transaction', $result['name']);
        $this->assertNull(ErrorWatch::getCurrentTransaction());
    }

    #[Test]
    public function it_can_capture_message(): void
    {
        // Mock transport on the existing app client to avoid handler leak
        $mockTransport = Mockery::mock(HttpTransport::class);
        $mockTransport->shouldReceive('send')
            ->once()
            ->with(Mockery::on(function ($payload) {
                return isset($payload['message'])
                    && $payload['message'] === 'Test message'
                    && $payload['level'] === 'info';
            }))
            ->andReturn(true);

        $client = app(MonitoringClient::class);

        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('transport');
        $property->setAccessible(true);
        $property->setValue($client, $mockTransport);

        $result = $client->captureMessage('Test message', 'info');

        $this->assertNotNull($result);
    }

    #[Test]
    public function it_can_capture_exception(): void
    {
        // Mock transport on the existing app client to avoid handler leak
        $mockTransport = Mockery::mock(HttpTransport::class);
        $mockTransport->shouldReceive('send')
            ->once()
            ->with(Mockery::on(function ($payload) {
                // Core SDK v2 payload: exception.type holds the class, message holds getMessage()
                return isset($payload['exception']['type'])
                    && $payload['exception']['type'] === 'RuntimeException'
                    && isset($payload['message'])
                    && str_contains($payload['message'], 'Test exception')
                    && $payload['level'] === 'error';
            }))
            ->andReturn(true);

        $client = app(MonitoringClient::class);

        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('transport');
        $property->setAccessible(true);
        $property->setValue($client, $mockTransport);

        $exception = new RuntimeException('Test exception');
        $result = $client->captureException($exception);

        $this->assertNotNull($result);
    }

    #[Test]
    public function it_returns_null_when_disabled(): void
    {
        $client = new MonitoringClient([
            'enabled' => false,
            'endpoint' => 'https://test.errorwatch.io',
            'api_key' => 'test-key',
        ]);

        $result = $client->captureMessage('Test', 'info');
        $this->assertNull($result);

        $result = $client->captureException(new RuntimeException('Test'));
        $this->assertNull($result);
    }

    #[Test]
    public function it_can_get_breadcrumb_manager(): void
    {
        $manager = $this->client->getBreadcrumbManager();

        $this->assertNotNull($manager);
        $this->assertInstanceOf(\ErrorWatch\Laravel\Breadcrumbs\BreadcrumbManager::class, $manager);
    }

    #[Test]
    public function it_can_get_user_context(): void
    {
        $context = $this->client->getUserContext();

        $this->assertNotNull($context);
        $this->assertInstanceOf(\ErrorWatch\Laravel\Context\UserContext::class, $context);
    }

    #[Test]
    public function it_can_get_transport(): void
    {
        $transport = $this->client->getTransport();

        $this->assertNotNull($transport);
        $this->assertInstanceOf(HttpTransport::class, $transport);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
