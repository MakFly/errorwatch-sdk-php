<?php

namespace ErrorWatch\Symfony\Tests\Unit\DependencyInjection;

use ErrorWatch\Symfony\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    private Configuration $configuration;
    private Processor $processor;

    protected function setUp(): void
    {
        $this->configuration = new Configuration();
        $this->processor = new Processor();
    }

    public function testDefaultConfiguration(): void
    {
        // api_key and endpoint are required when enabled=true
        // Note: enabled defaults to false, so we explicitly set it to test the enabled path
        $inputConfig = [
            'enabled' => true,
            'endpoint' => 'https://api.errorwatch.io',
            'api_key' => 'test-api-key',
        ];

        $config = $this->processor->processConfiguration($this->configuration, [$inputConfig]);

        $this->assertTrue($config['enabled']);
        $this->assertSame('https://api.errorwatch.io', $config['endpoint']);
        $this->assertSame('test-api-key', $config['api_key']);
        $this->assertSame('', $config['environment']);
        $this->assertNull($config['release']);

        // Replay defaults
        $this->assertFalse($config['replay']['enabled']);
        $this->assertFalse($config['replay']['debug']);
        $this->assertSame(0.1, $config['replay']['sample_rate']);

        // Breadcrumbs defaults
        $this->assertTrue($config['breadcrumbs']['enabled']);
        $this->assertSame(100, $config['breadcrumbs']['max_count']);

        // User context defaults
        $this->assertTrue($config['user_context']['enabled']);
        $this->assertTrue($config['user_context']['capture_ip']);

        // Console defaults
        $this->assertTrue($config['console']['enabled']);
        $this->assertTrue($config['console']['capture_exit_codes']);

        // Messenger defaults
        $this->assertTrue($config['messenger']['enabled']);
        $this->assertFalse($config['messenger']['capture_retries']);

        // Deprecations defaults
        $this->assertFalse($config['deprecations']['enabled']);

        // Security defaults
        $this->assertTrue($config['security']['enabled']);
        $this->assertFalse($config['security']['capture_login_success']);

        // APM defaults (including http_client)
        $this->assertTrue($config['apm']['http_client']['enabled']);
        $this->assertTrue($config['apm']['http_client']['capture_errors_as_breadcrumbs']);

        // Monolog defaults
        $this->assertTrue($config['monolog']['enabled']);
        $this->assertSame('warning', $config['monolog']['level']);
        $this->assertSame(['event', 'doctrine', 'http_client'], $config['monolog']['excluded_channels']);
        $this->assertTrue($config['monolog']['capture_context']);
        $this->assertTrue($config['monolog']['capture_extra']);
    }

    public function testCustomConfiguration(): void
    {
        $inputConfig = [
            'enabled' => false,
            'endpoint' => 'https://api.errorwatch.io',
            'api_key' => 'test-key-123',
            'environment' => 'production',
            'release' => '1.2.3',
            'replay' => [
                'enabled' => true,
                'debug' => true,
                'sample_rate' => 0.5,
            ],
            'breadcrumbs' => [
                'enabled' => false,
                'max_count' => 50,
            ],
            'user_context' => [
                'enabled' => false,
                'capture_ip' => false,
            ],
            'monolog' => [
                'enabled' => true,
                'level' => 'error',
                'excluded_channels' => ['event', 'doctrine', 'security'],
                'capture_context' => false,
                'capture_extra' => false,
            ],
        ];

        $config = $this->processor->processConfiguration($this->configuration, [$inputConfig]);

        $this->assertFalse($config['enabled']);
        $this->assertSame('https://api.errorwatch.io', $config['endpoint']);
        $this->assertSame('test-key-123', $config['api_key']);
        $this->assertSame('production', $config['environment']);
        $this->assertSame('1.2.3', $config['release']);

        $this->assertTrue($config['replay']['enabled']);
        $this->assertTrue($config['replay']['debug']);
        $this->assertSame(0.5, $config['replay']['sample_rate']);

        $this->assertFalse($config['breadcrumbs']['enabled']);
        $this->assertSame(50, $config['breadcrumbs']['max_count']);

        $this->assertFalse($config['user_context']['enabled']);
        $this->assertFalse($config['user_context']['capture_ip']);

        $this->assertTrue($config['monolog']['enabled']);
        $this->assertSame('error', $config['monolog']['level']);
        $this->assertSame(['event', 'doctrine', 'security'], $config['monolog']['excluded_channels']);
        $this->assertFalse($config['monolog']['capture_context']);
        $this->assertFalse($config['monolog']['capture_extra']);
    }

    public function testRequiresEndpointWhenEnabled(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $inputConfig = [
            'enabled' => true,
            'api_key' => 'test-key',
            'endpoint' => '',
        ];

        $this->processor->processConfiguration($this->configuration, [$inputConfig]);
    }

    public function testRequiresApiKeyWhenEnabled(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $inputConfig = [
            'enabled' => true,
            'endpoint' => 'https://api.errorwatch.io',
            'api_key' => '',
        ];

        $this->processor->processConfiguration($this->configuration, [$inputConfig]);
    }

    public function testRequiresApiKeyWhenReplayEnabled(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $inputConfig = [
            'enabled' => false,
            'endpoint' => 'https://api.errorwatch.io',
            'api_key' => '',
            'replay' => [
                'enabled' => true,
            ],
        ];

        $this->processor->processConfiguration($this->configuration, [$inputConfig]);
    }

    public function testNoApiKeyRequiredWhenDisabled(): void
    {
        $inputConfig = [
            'enabled' => false,
            'api_key' => '',
            'replay' => [
                'enabled' => false,
            ],
        ];

        $config = $this->processor->processConfiguration($this->configuration, [$inputConfig]);

        $this->assertFalse($config['enabled']);
    }

    public function testSampleRateValidation(): void
    {
        // Valid range: 0.0 to 1.0
        $validConfigs = [
            ['endpoint' => 'https://api.errorwatch.io', 'api_key' => 'test', 'replay' => ['sample_rate' => 0.0]],
            ['endpoint' => 'https://api.errorwatch.io', 'api_key' => 'test', 'replay' => ['sample_rate' => 0.5]],
            ['endpoint' => 'https://api.errorwatch.io', 'api_key' => 'test', 'replay' => ['sample_rate' => 1.0]],
        ];

        foreach ($validConfigs as $inputConfig) {
            $config = $this->processor->processConfiguration($this->configuration, [$inputConfig]);
            $this->assertArrayHasKey('replay', $config);
        }
    }

    public function testBreadcrumbsMaxCountValidation(): void
    {
        $inputConfig = [
            'endpoint' => 'https://api.errorwatch.io',
            'api_key' => 'test',
            'breadcrumbs' => [
                'max_count' => 250,
            ],
        ];

        $config = $this->processor->processConfiguration($this->configuration, [$inputConfig]);
        $this->assertSame(250, $config['breadcrumbs']['max_count']);
    }

    public function testMultipleConfigsAreMerged(): void
    {
        $configs = [
            ['endpoint' => 'https://api.errorwatch.io', 'api_key' => 'test', 'environment' => 'dev'],
            ['environment' => 'prod', 'release' => '2.0.0'],
        ];

        $config = $this->processor->processConfiguration($this->configuration, $configs);

        $this->assertSame('test', $config['api_key']);
        $this->assertSame('prod', $config['environment']); // Later config wins
        $this->assertSame('2.0.0', $config['release']);
    }
}
