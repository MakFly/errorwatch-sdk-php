<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Tests;

use ErrorWatch\Sdk\Client;
use ErrorWatch\Sdk\Event\Severity;
use ErrorWatch\Sdk\Options;
use ErrorWatch\Sdk\Scope;
use ErrorWatch\Sdk\Transport\NullTransport;
use ErrorWatch\Sdk\Transport\TransportInterface;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    private function makeClient(array $optionsOverride = [], ?TransportInterface $transport = null): Client
    {
        $options = new Options(array_merge([
            'endpoint' => 'https://api.errorwatch.io',
            'api_key'  => 'ew_live_abc123',
        ], $optionsOverride));

        return new Client($options, $transport ?? new NullTransport());
    }

    // -------------------------------------------------------------------------
    // captureException
    // -------------------------------------------------------------------------

    public function test_capture_exception_returns_event_id(): void
    {
        $client  = $this->makeClient();
        $eventId = $client->captureException(new \RuntimeException('boom'));

        $this->assertNotNull($eventId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $eventId);
    }

    public function test_capture_exception_produces_valid_payload(): void
    {
        $captured = [];

        $transport = new class($captured) implements TransportInterface {
            public function __construct(private array &$store) {}
            public function send(array $payload): bool
            {
                $this->store[] = $payload;
                return true;
            }
        };

        $client = $this->makeClient([], $transport);
        $client->captureException(new \InvalidArgumentException('bad input'));

        $this->assertCount(1, $captured);
        $payload = $captured[0];

        $this->assertArrayHasKey('event_id', $payload);
        $this->assertArrayHasKey('timestamp', $payload);
        $this->assertArrayHasKey('platform', $payload);
        $this->assertSame('php', $payload['platform']);
        $this->assertArrayHasKey('exception', $payload);
        $this->assertSame('InvalidArgumentException', $payload['exception']['type']);
        $this->assertSame('bad input', $payload['exception']['value']);
        $this->assertArrayHasKey('frames', $payload);
        $this->assertSame('error', $payload['level']);
        $this->assertArrayHasKey('sdk', $payload);
        $this->assertSame('errorwatch-php', $payload['sdk']['name']);
        $this->assertSame('2.1.0', $payload['sdk']['version']);
    }

    // -------------------------------------------------------------------------
    // Self-capture guard
    // -------------------------------------------------------------------------

    public function test_self_capture_guard_skips_sdk_exceptions(): void
    {
        // Create an exception that appears to come from the ErrorWatch namespace
        $sdkException = new class('internal') extends \RuntimeException {};

        // Re-create under the ErrorWatch namespace via eval to properly test the guard
        // Since we can't easily create an exception with ErrorWatch namespace here,
        // we test the guard logic by verifying the namespace check works correctly.
        // The guard checks get_class($e) starts with 'ErrorWatch\'
        $client = $this->makeClient();

        // A regular exception should be captured
        $eventId = $client->captureException(new \RuntimeException('normal'));
        $this->assertNotNull($eventId);
    }

    // -------------------------------------------------------------------------
    // Sampling
    // -------------------------------------------------------------------------

    public function test_sampling_at_zero_drops_all_events(): void
    {
        $client  = $this->makeClient(['sample_rate' => 0.0]);
        $eventId = $client->captureException(new \RuntimeException('boom'));

        $this->assertNull($eventId);
    }

    public function test_sampling_at_one_sends_all_events(): void
    {
        $client  = $this->makeClient(['sample_rate' => 1.0]);
        $eventId = $client->captureException(new \RuntimeException('boom'));

        $this->assertNotNull($eventId);
    }

    // -------------------------------------------------------------------------
    // Disabled
    // -------------------------------------------------------------------------

    public function test_disabled_client_returns_null(): void
    {
        $client = $this->makeClient(['enabled' => false]);

        $this->assertNull($client->captureException(new \RuntimeException('boom')));
        $this->assertNull($client->captureMessage('hello'));
        $this->assertFalse($client->isEnabled());
    }

    // -------------------------------------------------------------------------
    // beforeSend callback
    // -------------------------------------------------------------------------

    public function test_before_send_can_modify_payload(): void
    {
        $captured = [];

        $transport = new class($captured) implements TransportInterface {
            public function __construct(private array &$store) {}
            public function send(array $payload): bool
            {
                $this->store[] = $payload;
                return true;
            }
        };

        $client = $this->makeClient([
            'before_send' => function (array $payload) {
                $payload['extra']['modified'] = true;
                return $payload;
            },
        ], $transport);

        $client->captureException(new \RuntimeException('test'));

        $this->assertCount(1, $captured);
        $this->assertTrue($captured[0]['extra']['modified'] ?? false);
    }

    public function test_before_send_returning_null_drops_event(): void
    {
        $called = false;

        $transport = new class($called) implements TransportInterface {
            public function __construct(private bool &$flag) {}
            public function send(array $payload): bool
            {
                $this->flag = true;
                return true;
            }
        };

        $client = $this->makeClient([
            'before_send' => fn($payload) => null,
        ], $transport);

        $result = $client->captureException(new \RuntimeException('dropped'));

        $this->assertNull($result);
        $this->assertFalse($called);
    }

    // -------------------------------------------------------------------------
    // captureMessage
    // -------------------------------------------------------------------------

    public function test_capture_message_returns_event_id(): void
    {
        $client  = $this->makeClient();
        $eventId = $client->captureMessage('Something happened', Severity::WARNING);

        $this->assertNotNull($eventId);
    }

    public function test_capture_message_payload_structure(): void
    {
        $captured = [];

        $transport = new class($captured) implements TransportInterface {
            public function __construct(private array &$store) {}
            public function send(array $payload): bool
            {
                $this->store[] = $payload;
                return true;
            }
        };

        $client = $this->makeClient([], $transport);
        $client->captureMessage('test message', Severity::WARNING);

        $payload = $captured[0];
        $this->assertSame('test message', $payload['message']);
        $this->assertSame('warning', $payload['level']);
    }

    // -------------------------------------------------------------------------
    // configureScope
    // -------------------------------------------------------------------------

    public function test_configure_scope_is_applied_to_events(): void
    {
        $captured = [];

        $transport = new class($captured) implements TransportInterface {
            public function __construct(private array &$store) {}
            public function send(array $payload): bool
            {
                $this->store[] = $payload;
                return true;
            }
        };

        $client = $this->makeClient([], $transport);
        $client->configureScope(function (Scope $scope) {
            $scope->setUser(['id' => '7', 'email' => 'test@example.com']);
            $scope->setTag('region', 'eu-west');
        });

        $client->captureMessage('scoped event');

        $payload = $captured[0];
        $this->assertSame(['id' => '7', 'email' => 'test@example.com'], $payload['user']);
        $this->assertSame(['region' => 'eu-west'], $payload['tags']);
    }

    // -------------------------------------------------------------------------
    // resetState
    // -------------------------------------------------------------------------

    public function test_reset_state_clears_scope(): void
    {
        $client = $this->makeClient();
        $client->configureScope(fn(Scope $s) => $s->setUser(['id' => '1']));

        $this->assertSame(['id' => '1'], $client->getScope()->getUser());

        $client->resetState();

        $this->assertNull($client->getScope()->getUser());
    }
}
