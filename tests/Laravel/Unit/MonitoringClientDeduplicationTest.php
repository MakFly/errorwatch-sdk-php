<?php
declare(strict_types=1);

namespace ErrorWatch\Laravel\Tests\Unit;

use ErrorWatch\Laravel\Client\MonitoringClient;
use ErrorWatch\Laravel\Tests\TestCase;
use ErrorWatch\Laravel\Transport\HttpTransport;
use Mockery;
use RuntimeException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;

class MonitoringClientDeduplicationTest extends TestCase
{
    // Note: $client is declared as protected in the parent TestCase
    private HttpTransport $mockTransport;

    protected function setUp(): void
    {
        parent::setUp();

        // $this->client is already set by the parent TestCase's defineEnvironment()
        // We just need to inject a mock transport into it

        $this->mockTransport = Mockery::mock(HttpTransport::class);

        $reflection = new \ReflectionClass($this->client);
        $property   = $reflection->getProperty('transport');
        $property->setAccessible(true);
        $property->setValue($this->client, $this->mockTransport);
    }

    #[Test]
    public function it_returns_an_event_id_on_first_capture(): void
    {
        $this->mockTransport->shouldReceive('send')->once()->andReturn(true);

        $exception = new RuntimeException('First capture');

        $eventId = $this->client->captureException($exception);

        $this->assertNotNull($eventId);
        $this->assertIsString($eventId);
        $this->assertNotEmpty($eventId);
    }

    #[Test]
    public function it_returns_null_on_second_capture_of_same_exception_instance(): void
    {
        // First call: transport::send should be called once
        $this->mockTransport->shouldReceive('send')->once()->andReturn(true);

        $exception = new RuntimeException('Duplicate exception');

        $firstId = $this->client->captureException($exception);
        $this->assertNotNull($firstId);

        // Second call with same instance: transport::send must NOT be called again
        $secondId = $this->client->captureException($exception);
        $this->assertNull($secondId);
    }

    #[Test]
    public function it_returns_an_event_id_for_a_different_exception_instance(): void
    {
        $this->mockTransport->shouldReceive('send')->twice()->andReturn(true);

        $exceptionA = new RuntimeException('Exception A');
        $exceptionB = new RuntimeException('Exception A'); // same message, different object

        $idA = $this->client->captureException($exceptionA);
        $idB = $this->client->captureException($exceptionB);

        $this->assertNotNull($idA);
        $this->assertNotNull($idB);
        // Both captures should yield distinct IDs
        $this->assertNotEquals($idA, $idB);
    }

    #[Test]
    public function it_allows_recapture_after_clear_captured_exceptions(): void
    {
        $this->mockTransport->shouldReceive('send')->twice()->andReturn(true);

        $exception = new RuntimeException('Recaptured exception');

        $firstId = $this->client->captureException($exception);
        $this->assertNotNull($firstId);

        // Clear the deduplication tracker
        $this->client->clearCapturedExceptions();

        // Same instance should now be capturable again
        $secondId = $this->client->captureException($exception);
        $this->assertNotNull($secondId);
    }

    #[Test]
    public function it_includes_tags_in_the_payload_sent_to_transport(): void
    {
        $this->mockTransport->shouldReceive('send')
            ->once()
            ->with(Mockery::on(function (array $payload): bool {
                return isset($payload['tags'])
                    && $payload['tags']['env'] === 'testing'
                    && $payload['tags']['team'] === 'backend';
            }))
            ->andReturn(true);

        $exception = new RuntimeException('Exception with tags');

        $eventId = $this->client->captureException($exception, [
            'tags' => [
                'env'  => 'testing',
                'team' => 'backend',
            ],
        ]);

        $this->assertNotNull($eventId);
    }

    #[Test]
    public function it_includes_extra_data_in_the_payload_sent_to_transport(): void
    {
        $this->mockTransport->shouldReceive('send')
            ->once()
            ->with(Mockery::on(function (array $payload): bool {
                return isset($payload['extra'])
                    && $payload['extra']['user_id'] === 42
                    && $payload['extra']['action'] === 'checkout';
            }))
            ->andReturn(true);

        $exception = new LogicException('Exception with extra');

        $eventId = $this->client->captureException($exception, [
            'extra' => [
                'user_id' => 42,
                'action'  => 'checkout',
            ],
        ]);

        $this->assertNotNull($eventId);
    }

    #[Test]
    public function it_does_not_capture_when_sdk_is_disabled(): void
    {
        $disabledClient = new MonitoringClient([
            'enabled'  => false,
            'endpoint' => 'https://test.errorwatch.io',
            'api_key'  => 'test-key',
        ]);

        // No transport call expected
        $exception = new RuntimeException('Should not be captured');
        $result = $disabledClient->captureException($exception);

        $this->assertNull($result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
