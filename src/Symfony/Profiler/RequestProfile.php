<?php

declare(strict_types=1);

namespace ErrorWatch\Symfony\Profiler;

use ErrorWatch\Sdk\Profiler\RequestProfile as SharedRequestProfile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Symfony-flavoured request profile bag.
 *
 * Inherits the framework-agnostic core ({@see SharedRequestProfile}) and adds
 * `start(Request)` which snapshots the incoming Symfony HTTP request + the
 * matched route attributes into the shared bag.
 */
final class RequestProfile extends SharedRequestProfile
{
    /**
     * Snapshot the incoming Symfony request and start the profile.
     */
    public function start(Request $request): void
    {
        $this->startWithSnapshot(
            $this->snapshotRequest($request),
            $this->snapshotRoute($request),
        );
    }

    private function snapshotRequest(Request $request): array
    {
        $session = null;
        try {
            if ($request->hasSession()) {
                $sess = $request->getSession();
                if ($sess->isStarted()) {
                    $session = [
                        'id' => $sess->getId(),
                        'data' => self::scrubSensitiveKeys($sess->all()),
                    ];
                }
            }
        } catch (\Throwable) {
            $session = null;
        }

        return [
            'ip' => $request->getClientIp() ?? '',
            'method' => $request->getMethod(),
            'url' => $request->getUri(),
            'path' => $request->getPathInfo(),
            'query_string' => (string) ($request->getQueryString() ?? ''),
            'headers' => self::filterSensitiveHeaders($request->headers->all()),
            'content_type' => (string) ($request->headers->get('Content-Type') ?? ''),
            'content_length' => (int) ($request->headers->get('Content-Length') ?? 0),
            'cookies' => array_keys($request->cookies->all()),
            'session' => $session,
            'format' => (string) ($request->getRequestFormat() ?? ''),
        ];
    }

    private function snapshotRoute(Request $request): ?array
    {
        $route = $request->attributes->get('_route');
        if (!$route) {
            return null;
        }

        $controller = $request->attributes->get('_controller');
        $params = $request->attributes->get('_route_params', []);

        return [
            'uri' => $request->getPathInfo(),
            'name' => is_string($route) ? $route : null,
            'action' => is_string($controller) ? $controller : null,
            'controller' => is_string($controller) ? $controller : null,
            'middleware' => [],
            'parameters' => self::scrubSensitiveKeys(is_array($params) ? $params : []),
            'methods' => [$request->getMethod()],
            'domain' => $request->getHost(),
            'prefix' => null,
            'wheres' => [],
        ];
    }
}
