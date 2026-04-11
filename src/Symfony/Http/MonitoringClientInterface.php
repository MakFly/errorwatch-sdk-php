<?php

namespace ErrorWatch\Symfony\Http;

use Symfony\Contracts\HttpClient\ResponseInterface;

interface MonitoringClientInterface
{
    /**
     * Check if the client is properly configured with valid endpoint and API key.
     */
    public function isConfigured(): bool;

    /**
     * @param array<string, mixed> $payload
     */
    public function sendEvent(array $payload): ResponseInterface;

    /**
     * @param array<string, mixed> $payload
     */
    public function sendEventAsync(array $payload): void;

    /**
     * @param array<string, mixed> $payload
     */
    public function sendTransaction(array $payload): void;

    /**
     * @param array<string, mixed> $payload
     */
    public function sendMetrics(array $payload): void;

    /**
     * @param array<string, mixed> $payload
     */
    public function sendLog(array $payload): void;
}
