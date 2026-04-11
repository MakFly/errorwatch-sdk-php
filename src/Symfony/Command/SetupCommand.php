<?php

declare(strict_types=1);

namespace ErrorWatch\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Initialize ErrorWatch configuration files and environment variables.
 */
#[AsCommand(
    name: 'errorwatch:setup',
    description: 'Initialize ErrorWatch configuration',
)]
final class SetupCommand extends Command
{
    private const ENV_MARKER_START = '###> errorwatch/sdk-symfony ###';
    private const ENV_MARKER_END = '###< errorwatch/sdk-symfony ###';

    private const YAML_CONFIG = <<<'YAML'
error_watch:
    enabled: '%env(bool:ERRORWATCH_ENABLED)%'
    endpoint: '%env(default::ERRORWATCH_ENDPOINT)%'
    api_key: '%env(default::ERRORWATCH_API_KEY)%'
    environment: '%env(default::ERRORWATCH_ENV)%'
    release: '%env(default::ERRORWATCH_RELEASE)%'

    replay:
        enabled: false
        debug: false
        sample_rate: 0.1

    breadcrumbs:
        enabled: true
        max_count: 100

    user_context:
        enabled: true
        capture_ip: true

    console:
        enabled: true
        capture_exit_codes: true

    messenger:
        enabled: true
        capture_retries: false

    deprecations:
        enabled: false

    security:
        enabled: true
        capture_login_success: false

    apm:
        enabled: true
        request_tracking: true
        doctrine:
            enabled: true
            log_queries: true
        http_client:
            enabled: true
            capture_errors_as_breadcrumbs: true
        excluded_routes:
            - _profiler
            - _wdt
        n_plus_one_threshold: 5
        slow_query_threshold_ms: 500

    monolog:
        enabled: true
        level: warning
        excluded_channels:
            - event
            - doctrine
            - http_client
        capture_context: true
        capture_extra: true

YAML;

    private const ENV_BLOCK = <<<'ENV'

###> errorwatch/sdk-symfony ###
ERRORWATCH_ENABLED=true
ERRORWATCH_ENDPOINT=
ERRORWATCH_API_KEY=
ERRORWATCH_ENV=
ERRORWATCH_RELEASE=
###< errorwatch/sdk-symfony ###
ENV;

    public function __construct(
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->createYamlConfig($io);
        $this->appendEnvVars($io);

        $io->newLine();
        $io->text('<fg=yellow>Next steps:</>');
        $io->listing([
            'Set <comment>ERRORWATCH_ENDPOINT</comment> and <comment>ERRORWATCH_API_KEY</comment> in <comment>.env.local</comment>',
            'Run <comment>php bin/console debug:config error_watch</comment> to verify',
            'Clear cache: <comment>php bin/console cache:clear</comment>',
        ]);

        return Command::SUCCESS;
    }

    private function createYamlConfig(SymfonyStyle $io): void
    {
        $path = $this->projectDir.'/config/packages/error_watch.yaml';

        if (file_exists($path)) {
            $io->warning('config/packages/error_watch.yaml already exists, skipping.');

            return;
        }

        $dir = \dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, self::YAML_CONFIG);
        $io->success('Created config/packages/error_watch.yaml');
    }

    private function appendEnvVars(SymfonyStyle $io): void
    {
        $this->appendToEnvFile($io, '.env');

        $envLocal = $this->projectDir.'/.env.local';
        if (file_exists($envLocal)) {
            $this->appendToEnvFile($io, '.env.local');
        }
    }

    private function appendToEnvFile(SymfonyStyle $io, string $filename): void
    {
        $path = $this->projectDir.'/'.$filename;

        if (!file_exists($path)) {
            return;
        }

        $content = file_get_contents($path);

        if (str_contains($content, self::ENV_MARKER_START)) {
            $io->warning(\sprintf('ErrorWatch env vars already present in %s, skipping.', $filename));

            return;
        }

        file_put_contents($path, $content.self::ENV_BLOCK);
        $io->success(\sprintf('Appended ErrorWatch env vars to %s', $filename));
    }
}
