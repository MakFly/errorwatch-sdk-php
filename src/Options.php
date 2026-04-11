<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk;

class Options
{
    private readonly string  $endpoint;
    private readonly string  $apiKey;
    private readonly string  $environment;
    private readonly ?string $release;
    private readonly string  $serverName;
    private readonly string  $projectRoot;
    private readonly float   $sampleRate;
    private readonly int     $maxBreadcrumbs;
    /** @var callable|null */
    private readonly mixed $beforeSend;
    private readonly bool    $enabled;
    private readonly int     $timeout;

    public function __construct(array $config)
    {
        if (empty($config['endpoint'])) {
            throw new \InvalidArgumentException('ErrorWatch Options: "endpoint" is required.');
        }
        if (empty($config['api_key'])) {
            throw new \InvalidArgumentException('ErrorWatch Options: "api_key" is required.');
        }

        $this->endpoint       = rtrim((string) $config['endpoint'], '/');
        $this->apiKey         = (string) $config['api_key'];
        $this->environment    = (string) ($config['environment'] ?? 'production');
        $this->release        = isset($config['release']) ? (string) $config['release'] : null;
        $this->serverName     = (string) ($config['server_name'] ?? (gethostname() ?: 'unknown'));
        $this->projectRoot    = (string) ($config['project_root'] ?? (getcwd() ?: ''));
        $this->sampleRate     = (float)  ($config['sample_rate'] ?? 1.0);
        $this->maxBreadcrumbs = (int)    ($config['max_breadcrumbs'] ?? 100);
        $this->beforeSend     = isset($config['before_send']) && is_callable($config['before_send'])
            ? $config['before_send']
            : null;
        $this->enabled        = (bool) ($config['enabled'] ?? true);
        $this->timeout        = (int)  ($config['timeout'] ?? 5);
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function getRelease(): ?string
    {
        return $this->release;
    }

    public function getServerName(): string
    {
        return $this->serverName;
    }

    public function getProjectRoot(): string
    {
        return $this->projectRoot;
    }

    public function getSampleRate(): float
    {
        return $this->sampleRate;
    }

    public function getMaxBreadcrumbs(): int
    {
        return $this->maxBreadcrumbs;
    }

    public function getBeforeSend(): ?callable
    {
        return $this->beforeSend;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }
}
