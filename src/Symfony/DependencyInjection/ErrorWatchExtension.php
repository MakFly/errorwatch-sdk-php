<?php

namespace ErrorWatch\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class ErrorWatchExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('monolog')) {
            return;
        }

        $rawConfigs = $container->getExtensionConfig($this->getAlias());
        $processor = new Processor();
        $resolved = $processor->processConfiguration(new Configuration(), $rawConfigs ?: [[]]);

        if ($this->isExplicitlyDisabled($resolved['enabled']) || !$resolved['monolog']['enabled']) {
            return;
        }

        $container->prependExtensionConfig('monolog', [
            'handlers' => [
                'errorwatch' => [
                    'type' => 'service',
                    'id' => 'ErrorWatch\Symfony\Monolog\ErrorMonitoringHandler',
                    'level' => $resolved['monolog']['level'],
                ],
                'errorwatch_logs' => [
                    'type' => 'service',
                    'id' => 'ErrorWatch\Symfony\Monolog\LogStreamHandler',
                    'level' => $resolved['logs']['level'],
                ],
            ],
        ]);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $enabled = $config['enabled'];

        // Always load setup command (must be available even when bundle is disabled)
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../resources/config'));
        $loader->load('setup.yaml');

        // When explicitly disabled (bool false, not an env var), skip all service registration
        if ($this->isExplicitlyDisabled($enabled)) {
            $container->setParameter('error_watch.enabled', false);

            return;
        }

        // For env var placeholders: store as-is (resolved at runtime), load all services
        $container->setParameter('error_watch.enabled', $enabled);
        $loader->load('services.yaml');
        if (class_exists(\Twig\Extension\AbstractExtension::class)) {
            $loader->load('twig.yaml');
        }

        $container->setParameter('error_watch.endpoint', $config['endpoint']);
        $container->setParameter('error_watch.api_key', $config['api_key']);
        // Use kernel.environment by default if not explicitly configured
        $environment = $config['environment'] ?: $container->getParameter('kernel.environment');
        $container->setParameter('error_watch.environment', $environment);

        // Release: use config, or auto-detect from env/git
        $release = $config['release'] ?? $this->detectRelease($container);
        $container->setParameter('error_watch.release', $release);

        // Replay parameters
        $container->setParameter('error_watch.replay.enabled', $config['replay']['enabled']);
        $container->setParameter('error_watch.replay.debug', $config['replay']['debug']);
        $container->setParameter('error_watch.replay.sample_rate', $config['replay']['sample_rate']);

        // Breadcrumbs parameters
        $container->setParameter('error_watch.breadcrumbs.enabled', $config['breadcrumbs']['enabled']);
        $container->setParameter('error_watch.breadcrumbs.max_count', $config['breadcrumbs']['max_count']);

        // User context parameters
        $container->setParameter('error_watch.user_context.enabled', $config['user_context']['enabled']);
        $container->setParameter('error_watch.user_context.capture_ip', $config['user_context']['capture_ip']);

        // Console parameters
        $container->setParameter('error_watch.console.enabled', $config['console']['enabled']);
        $container->setParameter('error_watch.console.capture_exit_codes', $config['console']['capture_exit_codes']);

        if ($config['console']['enabled']) {
            $loader->load('console.yaml');
        }

        // Messenger parameters
        $container->setParameter('error_watch.messenger.enabled', $config['messenger']['enabled']);
        $container->setParameter('error_watch.messenger.capture_retries', $config['messenger']['capture_retries']);

        if ($config['messenger']['enabled'] && class_exists(\Symfony\Component\Messenger\Event\WorkerMessageFailedEvent::class)) {
            $loader->load('messenger.yaml');
        }

        // Deprecations parameters
        $container->setParameter('error_watch.deprecations.enabled', $config['deprecations']['enabled']);

        if ($config['deprecations']['enabled']) {
            $loader->load('deprecations.yaml');
        }

        // Security parameters
        $container->setParameter('error_watch.security.enabled', $config['security']['enabled']);
        $container->setParameter('error_watch.security.capture_login_success', $config['security']['capture_login_success']);

        if ($config['security']['enabled'] && class_exists(\Symfony\Component\Security\Http\Event\LoginFailureEvent::class)) {
            $loader->load('security.yaml');
        }

        // APM parameters
        $container->setParameter('error_watch.apm.enabled', $config['apm']['enabled']);
        $container->setParameter('error_watch.apm.request_tracking', $config['apm']['request_tracking']);
        $container->setParameter('error_watch.apm.doctrine.enabled', $config['apm']['doctrine']['enabled']);
        $container->setParameter('error_watch.apm.doctrine.log_queries', $config['apm']['doctrine']['log_queries']);
        $container->setParameter('error_watch.apm.http_client.enabled', $config['apm']['http_client']['enabled']);
        $container->setParameter('error_watch.apm.http_client.capture_errors_as_breadcrumbs', $config['apm']['http_client']['capture_errors_as_breadcrumbs']);
        $container->setParameter('error_watch.apm.excluded_routes', $config['apm']['excluded_routes']);
        $container->setParameter('error_watch.apm.n_plus_one_threshold', $config['apm']['n_plus_one_threshold']);
        $container->setParameter('error_watch.apm.slow_query_threshold_ms', $config['apm']['slow_query_threshold_ms']);

        if ($config['apm']['enabled']) {
            $loader->load('apm.yaml');
            if ($config['apm']['doctrine']['enabled'] && class_exists(\Doctrine\DBAL\Driver\Middleware::class)) {
                $loader->load('apm_doctrine.yaml');
            }
            if ($config['apm']['http_client']['enabled']) {
                $loader->load('http_client.yaml');
            }
        }

        // Monolog parameters
        $container->setParameter('error_watch.monolog.enabled', $config['monolog']['enabled']);
        $container->setParameter('error_watch.monolog.level', $config['monolog']['level']);
        $container->setParameter('error_watch.monolog.excluded_channels', $config['monolog']['excluded_channels']);
        $container->setParameter('error_watch.monolog.capture_context', $config['monolog']['capture_context']);
        $container->setParameter('error_watch.monolog.capture_extra', $config['monolog']['capture_extra']);

        $container->setParameter('error_watch.logs.enabled', $config['logs']['enabled']);
        $container->setParameter('error_watch.logs.level', $config['logs']['level']);
        $container->setParameter('error_watch.logs.excluded_channels', $config['logs']['excluded_channels']);
        $container->setParameter('error_watch.logs.capture_context', $config['logs']['capture_context']);
        $container->setParameter('error_watch.logs.capture_extra', $config['logs']['capture_extra']);

        // Load monolog handler conditionally
        if ($config['monolog']['enabled'] && class_exists(\Monolog\Handler\AbstractProcessingHandler::class)) {
            $loader->load('monolog.yaml');
        }
    }

    /**
     * Check if enabled is explicitly set to false (not an env var placeholder).
     */
    private function isExplicitlyDisabled(mixed $enabled): bool
    {
        if ($enabled === false) {
            return true;
        }

        if (\is_string($enabled) && \in_array(strtolower($enabled), ['false', '0', 'no', ''], true)) {
            return true;
        }

        return false;
    }

    /**
     * Auto-detect release version from environment or git.
     */
    private function detectRelease(ContainerBuilder $container): ?string
    {
        // 1. Check ERRORWATCH_RELEASE first (from recipe env)
        $release = $_ENV['ERRORWATCH_RELEASE'] ?? $_SERVER['ERRORWATCH_RELEASE'] ?? null;
        if ($release && '' !== $release) {
            return $release;
        }

        // 2. Check APP_VERSION environment variable
        $appVersion = $_ENV['APP_VERSION'] ?? $_SERVER['APP_VERSION'] ?? getenv('APP_VERSION');
        if ($appVersion && '' !== $appVersion) {
            return $appVersion;
        }

        // 3. Try to get git commit hash (check current dir and parent dirs)
        $projectDir = $container->getParameter('kernel.project_dir');
        $gitDir = $this->findGitDir($projectDir);

        if ($gitDir) {
            $gitHeadFile = $gitDir.'/HEAD';
            if (file_exists($gitHeadFile)) {
                $head = trim(file_get_contents($gitHeadFile));

                // Check if it's a ref (branch) or direct commit
                if (str_starts_with($head, 'ref: ')) {
                    $refPath = $gitDir.'/'.substr($head, 5);
                    if (file_exists($refPath)) {
                        return substr(trim(file_get_contents($refPath)), 0, 8); // Short hash
                    }
                } else {
                    return substr($head, 0, 8); // Direct commit hash
                }
            }
        }

        return null;
    }

    /**
     * Find .git directory (searches current dir and up to 3 parent dirs).
     */
    private function findGitDir(string $startDir): ?string
    {
        $dir = $startDir;
        $maxLevels = 4; // Check current + 3 parent levels

        for ($i = 0; $i < $maxLevels; ++$i) {
            $gitDir = $dir.'/.git';
            if (is_dir($gitDir)) {
                return $gitDir;
            }
            $parentDir = dirname($dir);
            if ($parentDir === $dir) {
                break; // Reached root
            }
            $dir = $parentDir;
        }

        return null;
    }
}
