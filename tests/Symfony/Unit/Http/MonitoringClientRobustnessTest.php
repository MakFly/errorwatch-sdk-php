<?php

namespace ErrorWatch\Symfony\Tests\Unit\Http;

use ErrorWatch\Symfony\Http\MonitoringClient;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Tests for MonitoringClient robustness against NULL/empty values.
 * Ensures the SDK never crashes the host application, even when misconfigured.
 */
class MonitoringClientRobustnessTest extends TestCase
{
    /**
     * Test that NULL endpoint does not crash the client.
     */
    public function testDoesNotCrashWithNullEndpoint(): void
    {
        $client = new MonitoringClient(null, 'test-key');

        // Should not throw any exception
        $client->sendEventAsync(['message' => 'Test']);

        // If we reach here, the test passed
        $this->assertFalse($client->isConfigured());
    }

    /**
     * Test that NULL API key does not crash the client.
     */
    public function testDoesNotCrashWithNullApiKey(): void
    {
        $client = new MonitoringClient('https://example.com', null);

        // Should not throw any exception
        $client->sendEventAsync(['message' => 'Test']);

        // If we reach here, the test passed
        $this->assertFalse($client->isConfigured());
    }

    /**
     * Test that both NULL values do not crash the client.
     */
    public function testDoesNotCrashWithBothNull(): void
    {
        $client = new MonitoringClient(null, null);

        // Should not throw any exception
        $client->sendEventAsync(['message' => 'Test']);

        // If we reach here, the test passed
        $this->assertFalse($client->isConfigured());
    }

    /**
     * Test that empty string endpoint is handled gracefully.
     */
    public function testDoesNotCrashWithEmptyEndpoint(): void
    {
        $client = new MonitoringClient('', 'test-key');

        // Should not throw any exception
        $client->sendEventAsync(['message' => 'Test']);

        // If we reach here, the test passed
        $this->assertFalse($client->isConfigured());
    }

    /**
     * Test that empty string API key is handled gracefully.
     */
    public function testDoesNotCrashWithEmptyApiKey(): void
    {
        $client = new MonitoringClient('https://example.com', '');

        // Should not throw any exception
        $client->sendEventAsync(['message' => 'Test']);

        // If we reach here, the test passed
        $this->assertFalse($client->isConfigured());
    }

    /**
     * Test that isConfigured returns false for NULL endpoint.
     */
    public function testIsConfiguredReturnsFalseForNullEndpoint(): void
    {
        $client = new MonitoringClient(null, 'test-key');

        $this->assertFalse($client->isConfigured());
    }

    /**
     * Test that isConfigured returns false for NULL API key.
     */
    public function testIsConfiguredReturnsFalseForNullApiKey(): void
    {
        $client = new MonitoringClient('https://example.com', null);

        $this->assertFalse($client->isConfigured());
    }

    /**
     * Test that isConfigured returns false for empty endpoint.
     */
    public function testIsConfiguredReturnsFalseForEmptyEndpoint(): void
    {
        $client = new MonitoringClient('', 'test-key');

        $this->assertFalse($client->isConfigured());
    }

    /**
     * Test that isConfigured returns false for empty API key.
     */
    public function testIsConfiguredReturnsFalseForEmptyApiKey(): void
    {
        $client = new MonitoringClient('https://example.com', '');

        $this->assertFalse($client->isConfigured());
    }

    /**
     * Test that isConfigured returns true when both values are set.
     */
    public function testIsConfiguredReturnsTrueWhenBothSet(): void
    {
        $client = new MonitoringClient('https://example.com', 'test-key');

        $this->assertTrue($client->isConfigured());
    }

    /**
     * Test that sendEvent returns a mock response when not configured.
     */
    public function testSendEventReturnsMockResponseWhenNotConfigured(): void
    {
        $client = new MonitoringClient(null, null);

        $response = $client->sendEvent(['message' => 'Test']);

        // Should return a ResponseInterface, not throw
        $this->assertInstanceOf(ResponseInterface::class, $response);

        // Mock response should return status code 0
        $this->assertSame(0, $response->getStatusCode());
    }

    /**
     * Test that sendTransaction does not crash when not configured.
     */
    public function testSendTransactionDoesNotCrashWhenNotConfigured(): void
    {
        $client = new MonitoringClient(null, null);

        // Should not throw any exception
        $client->sendTransaction(['name' => 'test']);

        // If we reach here, the test passed
        $this->assertFalse($client->isConfigured());
    }

    /**
     * Test that sendMetrics does not crash when not configured.
     */
    public function testSendMetricsDoesNotCrashWhenNotConfigured(): void
    {
        $client = new MonitoringClient(null, null);

        // Should not throw any exception
        $client->sendMetrics(['metrics' => []]);

        // If we reach here, the test passed
        $this->assertFalse($client->isConfigured());
    }

    /**
     * Test that sendLog does not crash when not configured.
     */
    public function testSendLogDoesNotCrashWhenNotConfigured(): void
    {
        $client = new MonitoringClient(null, null);

        // Should not throw any exception
        $client->sendLog(['message' => 'test']);

        // If we reach here, the test passed
        $this->assertFalse($client->isConfigured());
    }
}
