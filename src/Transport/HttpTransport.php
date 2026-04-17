<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class HttpTransport implements TransportInterface
{
    private readonly Client          $client;
    private readonly LoggerInterface $logger;
    private readonly string          $eventUrl;

    public function __construct(
        private readonly string  $endpoint,
        private readonly string  $apiKey,
        private readonly int     $timeout = 5,
        ?LoggerInterface         $logger  = null,
        ?Client                  $client  = null,
    ) {
        $this->logger   = $logger ?? new NullLogger();
        // /api/v1/envelope accepts the rich Sentry-style payload produced
        // by Sdk\Event::toPayload(). Legacy /api/v1/event remains for flat
        // (file/line/stack) payloads emitted by other integrations.
        $this->eventUrl = rtrim($endpoint, '/') . '/api/v1/envelope';
        $this->client   = $client ?? new Client([
            'timeout'         => $timeout,
            'connect_timeout' => 3,
            'http_errors'     => false,
        ]);
    }

    public function send(array $payload): bool
    {
        try {
            $response = $this->client->post($this->eventUrl, [
                'headers' => [
                    'X-API-Key'    => $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'json'    => $payload,
                'timeout' => $this->timeout,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                return true;
            }

            $this->logger->warning('[ErrorWatch] Unexpected HTTP response', [
                'status'   => $statusCode,
                'event_id' => $payload['event_id'] ?? null,
            ]);

            return false;
        } catch (GuzzleException $e) {
            $this->logger->error('[ErrorWatch] HTTP transport error: ' . $e->getMessage(), [
                'event_id' => $payload['event_id'] ?? null,
            ]);

            return false;
        } catch (\Throwable $e) {
            // Absolute last resort — the SDK must never propagate exceptions
            $this->logger->error('[ErrorWatch] Unexpected transport error: ' . $e->getMessage());

            return false;
        }
    }
}
