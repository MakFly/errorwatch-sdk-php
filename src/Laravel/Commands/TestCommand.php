<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Commands;

use ErrorWatch\Laravel\Client\MonitoringClient;
use ErrorWatch\Laravel\Facades\ErrorWatch;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class TestCommand extends Command
{
    protected $signature = 'errorwatch:test
                            {--message= : Custom message to send}
                            {--exception : Send a test exception}';

    protected $description = 'Send a test event to ErrorWatch to verify configuration';

    protected MonitoringClient $client;

    public function handle(MonitoringClient $client): int
    {
        $this->client = $client;

        $this->info('Testing ErrorWatch SDK configuration...');
        $this->line('');

        // Check if SDK is enabled
        if (!$client->isEnabled()) {
            $this->error('✗ ErrorWatch SDK is disabled.');
            $this->line('');
            $this->comment('Set ERRORWATCH_ENABLED=true in your .env file.');
            return self::FAILURE;
        }

        // Check configuration
        $this->checkConfiguration();

        // Send test event
        $this->sendTestEvent();

        return self::SUCCESS;
    }

    /**
     * Check the SDK configuration.
     */
    protected function checkConfiguration(): void
    {
        $endpoint = $this->client->getConfig('endpoint');
        $apiKey = $this->client->getConfig('api_key');

        if (empty($endpoint)) {
            $this->error('✗ ERRORWATCH_ENDPOINT is not configured.');
            $this->line('');
            $this->comment('Add ERRORWATCH_ENDPOINT to your .env file.');
            exit(1);
        }

        if (empty($apiKey)) {
            $this->error('✗ ERRORWATCH_API_KEY is not configured.');
            $this->line('');
            $this->comment('Add ERRORWATCH_API_KEY to your .env file.');
            exit(1);
        }

        $this->info('✓ Configuration valid');
        $this->line("  Endpoint: {$endpoint}");
        $this->line("  API Key: " . substr($apiKey, 0, 8) . '...' . substr($apiKey, -4));
        $this->line("  Environment: " . $this->client->getConfig('environment', 'production'));
        $this->line('');
    }

    /**
     * Send a test event.
     */
    protected function sendTestEvent(): void
    {
        if ($this->option('exception')) {
            $this->sendTestException();
        } else {
            $this->sendTestMessage();
        }
    }

    /**
     * Send a test message.
     */
    protected function sendTestMessage(): void
    {
        $message = $this->option('message') ?? 'Test event from Laravel SDK';
        $environment = $this->client->getConfig('environment', 'production');

        $this->info('Sending test message...');
        $this->line('');

        $eventId = ErrorWatch::captureMessage($message, 'info', [
            'extra' => [
                'test' => true,
                'environment' => $environment,
                'laravel_version' => app()->version(),
                'php_version' => PHP_VERSION,
                'timestamp' => now()->toIso8601String(),
            ],
            'tags' => [
                'source' => 'errorwatch:test',
            ],
        ]);

        $this->info('✓ Test message sent successfully!');

        if ($eventId) {
            $this->line("  Event ID: {$eventId}");
        }

        $this->line('');
        $this->comment('Check your ErrorWatch dashboard to verify the event was received.');
    }

    /**
     * Send a test exception.
     */
    protected function sendTestException(): void
    {
        $this->info('Sending test exception...');
        $this->line('');

        try {
            throw new RuntimeException('Test exception from Laravel SDK');
        } catch (Throwable $e) {
            $eventId = ErrorWatch::captureException($e, [
                'extra' => [
                    'test' => true,
                    'laravel_version' => app()->version(),
                    'php_version' => PHP_VERSION,
                    'timestamp' => now()->toIso8601String(),
                ],
                'tags' => [
                    'source' => 'errorwatch:test',
                ],
            ]);

            $this->info('✓ Test exception sent successfully!');

            if ($eventId) {
                $this->line("  Event ID: {$eventId}");
            }

            $this->line('');
            $this->comment('Check your ErrorWatch dashboard to verify the exception was received.');
        }
    }
}
