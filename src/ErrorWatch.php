<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk;

/**
 * ErrorWatch
 *
 * Convenience factory for the framework-agnostic client, mirroring the
 * ergonomics of Flare's `Flare::make($apiToken)`. Defaults to the Flare
 * protocol (flat-attribute payload → POST /api/v1/errors).
 *
 *   $client = ErrorWatch::make('ew_live_xxx');
 *   $client->captureException($e);
 */
final class ErrorWatch
{
    /**
     * @param array<string, mixed> $config Extra Options overrides (e.g. environment, release, transport_mode).
     */
    public static function make(string $apiToken, ?string $endpoint = null, array $config = []): Client
    {
        $endpoint = $endpoint
            ?: (getenv('ERRORWATCH_ENDPOINT') ?: null)
            ?: 'https://api.errorwatch.io';

        $options = new Options(array_merge([
            'protocol' => 'flare',
        ], $config, [
            'endpoint' => $endpoint,
            'api_key'  => $apiToken,
        ]));

        return new Client($options);
    }
}
