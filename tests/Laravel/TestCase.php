<?php

namespace ErrorWatch\Laravel\Tests;

use ErrorWatch\Laravel\Client\MonitoringClient;
use ErrorWatch\Laravel\ErrorWatchServiceProvider;
use ErrorWatch\Laravel\Transport\HttpTransport;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected MonitoringClient $client;
    protected HttpTransport $transport;

    protected function getPackageProviders($app): array
    {
        return [
            ErrorWatchServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'ErrorWatch' => \ErrorWatch\Laravel\Facades\ErrorWatch::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('errorwatch', [
            'enabled' => true,
            'endpoint' => 'https://test.errorwatch.io',
            'api_key' => 'test-api-key-12345',
            'environment' => 'testing',
            'release' => '1.0.0',
            'replay' => [
                'enabled' => false,
                'sample_rate' => 0.1,
            ],
            'breadcrumbs' => [
                'enabled' => true,
                'max_count' => 100,
            ],
            'user_context' => [
                'enabled' => true,
                'capture_ip' => true,
            ],
            'console' => [
                'enabled' => true,
                'capture_exit_codes' => true,
            ],
            'queue' => [
                'enabled' => true,
                'capture_retries' => false,
            ],
            'deprecations' => [
                'enabled' => false,
            ],
            'security' => [
                'enabled' => true,
                'capture_login_success' => false,
            ],
            'apm' => [
                'enabled' => true,
                'request_tracking' => true,
                'eloquent' => [
                    'enabled' => true,
                    'log_queries' => true,
                ],
                'http_client' => [
                    'enabled' => true,
                    'capture_errors_as_breadcrumbs' => true,
                ],
                'n_plus_one_threshold' => 5,
                'slow_query_threshold_ms' => 500,
                'excluded_routes' => [],
            ],
            'logging' => [
                'enabled' => true,
                'level' => 'error',
                'excluded_channels' => ['errorwatch'],
            ],
            'exceptions' => [
                'enabled' => true,
            ],
            'logs' => [
                'enabled' => true,
                'level' => 'debug',
                'excluded_channels' => [],
            ],
        ]);

        $this->client = $app->make(MonitoringClient::class);
        $this->transport = $this->client->getTransport();
    }

    /**
     * Create a mock transport for testing.
     */
    protected function mockTransport(): HttpTransport
    {
        return $this->createMock(HttpTransport::class);
    }
}
