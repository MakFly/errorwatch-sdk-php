<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Tests\Transport;

use ErrorWatch\Sdk\Transport\HttpTransport;
use ErrorWatch\Sdk\Transport\NullTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class HttpTransportTest extends TestCase
{
    private function makeTransport(array $responses): HttpTransport
    {
        $mock    = new MockHandler($responses);
        $stack   = HandlerStack::create($mock);
        $client  = new Client(['handler' => $stack]);

        return new HttpTransport(
            endpoint: 'https://api.errorwatch.io',
            apiKey:   'ew_live_abc123',
            timeout:  5,
            logger:   null,
            client:   $client,
        );
    }

    private function samplePayload(): array
    {
        return [
            'event_id'   => 'abc123',
            'platform'   => 'php',
            'level'      => 'error',
            'message'    => 'Test error',
            'timestamp'  => '2026-04-11T12:00:00+00:00',
        ];
    }

    public function test_returns_true_on_2xx(): void
    {
        $transport = $this->makeTransport([new Response(200)]);
        $result    = $transport->send($this->samplePayload());

        $this->assertTrue($result);
    }

    public function test_returns_true_on_201(): void
    {
        $transport = $this->makeTransport([new Response(201)]);
        $result    = $transport->send($this->samplePayload());

        $this->assertTrue($result);
    }

    public function test_returns_false_on_4xx(): void
    {
        $transport = $this->makeTransport([new Response(401)]);
        $result    = $transport->send($this->samplePayload());

        $this->assertFalse($result);
    }

    public function test_returns_false_on_5xx(): void
    {
        $transport = $this->makeTransport([new Response(500)]);
        $result    = $transport->send($this->samplePayload());

        $this->assertFalse($result);
    }

    public function test_returns_false_on_network_error(): void
    {
        $mock    = new MockHandler([new \GuzzleHttp\Exception\ConnectException(
            'Network error',
            new \GuzzleHttp\Psr7\Request('POST', 'https://api.errorwatch.io/api/v1/event'),
        )]);
        $stack   = HandlerStack::create($mock);
        $client  = new Client(['handler' => $stack]);

        $transport = new HttpTransport(
            endpoint: 'https://api.errorwatch.io',
            apiKey:   'ew_live_abc123',
            client:   $client,
        );

        // Must not throw — silent failure expected
        $result = $transport->send($this->samplePayload());

        $this->assertFalse($result);
    }

    public function test_null_transport_always_returns_true(): void
    {
        $transport = new NullTransport();

        $this->assertTrue($transport->send(['event_id' => 'x']));
    }
}
