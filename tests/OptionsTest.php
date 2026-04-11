<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Tests;

use ErrorWatch\Sdk\Options;
use PHPUnit\Framework\TestCase;

class OptionsTest extends TestCase
{
    public function test_requires_endpoint(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/endpoint/');

        new Options(['api_key' => 'ew_live_xxx']);
    }

    public function test_requires_api_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/api_key/');

        new Options(['endpoint' => 'https://api.errorwatch.io']);
    }

    public function test_defaults(): void
    {
        $options = new Options([
            'endpoint' => 'https://api.errorwatch.io',
            'api_key'  => 'ew_live_abc123',
        ]);

        $this->assertSame('production', $options->getEnvironment());
        $this->assertNull($options->getRelease());
        $this->assertIsString($options->getServerName());
        $this->assertIsString($options->getProjectRoot());
        $this->assertSame(1.0, $options->getSampleRate());
        $this->assertSame(100, $options->getMaxBreadcrumbs());
        $this->assertNull($options->getBeforeSend());
        $this->assertTrue($options->isEnabled());
        $this->assertSame(5, $options->getTimeout());
    }

    public function test_trailing_slash_stripped_from_endpoint(): void
    {
        $options = new Options([
            'endpoint' => 'https://api.errorwatch.io/',
            'api_key'  => 'ew_live_abc123',
        ]);

        $this->assertSame('https://api.errorwatch.io', $options->getEndpoint());
    }

    public function test_custom_values(): void
    {
        $beforeSend = static fn($payload) => $payload;

        $options = new Options([
            'endpoint'        => 'https://api.errorwatch.io',
            'api_key'         => 'ew_live_abc123',
            'environment'     => 'staging',
            'release'         => '2.0.0',
            'server_name'     => 'my-server',
            'project_root'    => '/var/www/app',
            'sample_rate'     => 0.5,
            'max_breadcrumbs' => 50,
            'before_send'     => $beforeSend,
            'enabled'         => false,
            'timeout'         => 10,
        ]);

        $this->assertSame('staging', $options->getEnvironment());
        $this->assertSame('2.0.0', $options->getRelease());
        $this->assertSame('my-server', $options->getServerName());
        $this->assertSame('/var/www/app', $options->getProjectRoot());
        $this->assertSame(0.5, $options->getSampleRate());
        $this->assertSame(50, $options->getMaxBreadcrumbs());
        $this->assertSame($beforeSend, $options->getBeforeSend());
        $this->assertFalse($options->isEnabled());
        $this->assertSame(10, $options->getTimeout());
    }

    public function test_before_send_non_callable_is_ignored(): void
    {
        $options = new Options([
            'endpoint'    => 'https://api.errorwatch.io',
            'api_key'     => 'ew_live_abc123',
            'before_send' => 'not_a_callable',
        ]);

        $this->assertNull($options->getBeforeSend());
    }
}
