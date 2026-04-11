<?php

namespace ErrorWatch\Symfony\Tests\Unit\Http;

use ErrorWatch\Symfony\Http\MonitoringClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MonitoringClientTest extends TestCase
{
    public function testSendsPostRequestWithCorrectHeaders(): void
    {
        $captured = [];
        $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
            ];

            return new MockResponse('', ['http_code' => 200]);
        });

        $client = new MonitoringClient('http://localhost:8000', 'test-api-key', $mockClient);
        $payload = [
            'message' => 'Test error',
            'file' => '/test.php',
            'line' => 1,
            'stack' => 'Stack trace',
        ];

        $client->sendEvent($payload);

        $headers = [];
        foreach ($captured['options']['headers'] as $header) {
            if (!is_string($header) || !str_contains($header, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $header, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        $this->assertSame('POST', $captured['method']);
        $this->assertSame('http://localhost:8000/api/v1/event', $captured['url']);
        $this->assertSame('application/json', $headers['content-type'] ?? null);
        $this->assertSame('test-api-key', $headers['x-api-key'] ?? null);
        $this->assertSame(json_encode($payload), $captured['options']['body']);
        $this->assertEquals(1, $captured['options']['timeout']);
    }

    public function testSendEventAsyncSwallowsExceptions(): void
    {
        $mockClient = new MockHttpClient(function () {
            throw new \RuntimeException('Network error');
        });

        $client = new MonitoringClient('http://invalid-host:9999', 'test-key');

        $this->expectNotToPerformAssertions();
        $client->sendEventAsync(['message' => 'Test']);
    }
}
