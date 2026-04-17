<?php

namespace ErrorWatch\Symfony\Service;

use ErrorWatch\Sdk\Client;
use ErrorWatch\Sdk\Options;
use ErrorWatch\Sdk\Tracing\TraceContext;

/**
 * Factory that builds an ErrorWatch\Sdk\Client from Symfony DI parameters.
 *
 * Since the Client is a service but the TraceContext is request-scoped
 * (mutated by the RequestSubscriber on each request), we wire them via
 * `setTraceContext` so the same singleton Client sees fresh context
 * without needing to be rebuilt between requests.
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
        ?TraceContext $traceContext = null,
    ): Client {
        $isEnabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);

        if (!$isEnabled || '' === ($endpoint ?? '') || '' === ($apiKey ?? '')) {
            $client = new Client(new Options([
                'endpoint'        => $endpoint ?: 'http://localhost',
                'api_key'         => $apiKey ?: 'disabled',
                'environment'     => $environment ?? 'prod',
                'release'         => $release,
                'max_breadcrumbs' => $maxBreadcrumbs,
                'project_root'    => $projectRoot ?? '',
                'enabled'         => false,
            ]));
        } else {
            $client = new Client(new Options([
                'endpoint'        => $endpoint,
                'api_key'         => $apiKey,
                'environment'     => $environment ?? 'prod',
                'release'         => $release,
                'max_breadcrumbs' => $maxBreadcrumbs,
                'project_root'    => $projectRoot ?? '',
                'enabled'         => true,
            ]));
        }

        if (null !== $traceContext) {
            $client->setTraceContext($traceContext);
        }

        return $client;
    }
}
