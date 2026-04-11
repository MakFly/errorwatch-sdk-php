<?php

namespace ErrorWatch\Symfony\Service;

use ErrorWatch\Sdk\Client;
use ErrorWatch\Sdk\Options;

/**
 * Factory that builds an ErrorWatch\Sdk\Client from Symfony DI parameters.
 */
final class ClientFactory
{
    public static function create(
        mixed $enabled,
        ?string $endpoint,
        ?string $apiKey,
        ?string $environment,
        ?string $release,
        int $maxBreadcrumbs = 100,
        ?string $projectRoot = null,
    ): Client {
        // Resolve runtime enabled flag
        $isEnabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);

        // When disabled or not configured, build a NullTransport client
        if (!$isEnabled || '' === ($endpoint ?? '') || '' === ($apiKey ?? '')) {
            // Build with dummy values but disabled — Options validates endpoint/api_key
            // so we pass them through; the NullTransport will be selected when !isEnabled
            return new Client(new Options([
                'endpoint'        => $endpoint ?: 'http://localhost',
                'api_key'         => $apiKey ?: 'disabled',
                'environment'     => $environment ?? 'prod',
                'release'         => $release,
                'max_breadcrumbs' => $maxBreadcrumbs,
                'project_root'    => $projectRoot ?? '',
                'enabled'         => false,
            ]));
        }

        return new Client(new Options([
            'endpoint'        => $endpoint,
            'api_key'         => $apiKey,
            'environment'     => $environment ?? 'prod',
            'release'         => $release,
            'max_breadcrumbs' => $maxBreadcrumbs,
            'project_root'    => $projectRoot ?? '',
            'enabled'         => true,
        ]));
    }
}
