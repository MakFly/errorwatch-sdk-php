<?php

namespace ErrorWatch\Symfony\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Manages session replay functionality
 * Generates and stores session IDs for linking errors to replay sessions.
 */
class SessionReplayManager
{
    private const SESSION_KEY = 'error_watch_session_id';

    private RequestStack $requestStack;
    private string $endpoint;
    private string $apiKey;
    private bool $enabled;
    private bool $debug;
    private float $sampleRate;
    private ?string $release;
    private bool $configured;

    public function __construct(
        RequestStack $requestStack,
        ?string $endpoint,
        ?string $apiKey,
        bool $enabled = false,
        bool $debug = false,
        float $sampleRate = 0.1,
        ?string $release = null,
        ?ErrorWatchLogger $logger = null,
    ) {
        $this->requestStack = $requestStack;
        $this->endpoint = $endpoint ?? '';
        $this->apiKey = $apiKey ?? '';
        $this->enabled = $enabled;
        $this->debug = $debug;
        $this->sampleRate = max(0.0, min(1.0, $sampleRate)); // Clamp between 0 and 1
        $this->release = $release;

        // Validate configuration
        $this->configured = true;
        if ('' === $this->endpoint || '' === $this->apiKey) {
            $this->configured = false;
            if ($enabled) {
                $logger?->warning('SessionReplay enabled but endpoint or api_key is empty. Session replay will be disabled.', [
                    'hint' => 'Set ERRORWATCH_ENDPOINT and ERRORWATCH_API_KEY in your .env file',
                ]);
            }
        }
    }

    /**
     * Get or create session ID for replay linking
     * Returns null if replay is disabled, not configured, or if request doesn't pass sample rate.
     *
     * Note: SessionId is scoped to the current HTTP request (page load),
     * not the browser session. Each page load gets a new sessionId.
     */
    public function getSessionId(): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        if (!$this->configured) {
            return null;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return null;
        }

        // Use request attributes (non-persistent) instead of session (persistent)
        // This ensures a new sessionId per page load, not per browser session
        $sessionId = $request->attributes->get(self::SESSION_KEY);

        if (null === $sessionId) {
            // Check sample rate - only create session for sampled requests
            if (!$this->shouldSample()) {
                return null;
            }

            $sessionId = $this->generateUuid();
            $request->attributes->set(self::SESSION_KEY, $sessionId);
        }

        return $sessionId;
    }

    /**
     * Check if replay is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if the manager is properly configured.
     */
    public function isConfigured(): bool
    {
        return $this->configured;
    }

    /**
     * Get endpoint URL.
     */
    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * Get API key.
     */
    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * Get sample rate.
     */
    public function getSampleRate(): float
    {
        return $this->sampleRate;
    }

    /**
     * Get JavaScript configuration for the replay script.
     *
     * @return array<string, mixed>
     */
    public function getJsConfig(): array
    {
        return [
            'enabled' => $this->enabled,
            'debug' => $this->debug,
            'endpoint' => rtrim($this->endpoint, '/'),
            'apiKey' => $this->apiKey,
            'sessionId' => $this->getSessionId(),
            'sampleRate' => $this->sampleRate,
            'release' => $this->release,
        ];
    }

    /**
     * Check if this request should be sampled based on sample rate.
     */
    private function shouldSample(): bool
    {
        if ($this->sampleRate >= 1.0) {
            return true;
        }
        if ($this->sampleRate <= 0.0) {
            return false;
        }

        return (mt_rand(1, 10000) / 10000) <= $this->sampleRate;
    }

    /**
     * Generate a UUID v4.
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xFFFF),
            mt_rand(0, 0xFFFF),
            mt_rand(0, 0xFFFF),
            mt_rand(0, 0x0FFF) | 0x4000,
            mt_rand(0, 0x3FFF) | 0x8000,
            mt_rand(0, 0xFFFF),
            mt_rand(0, 0xFFFF),
            mt_rand(0, 0xFFFF)
        );
    }
}
