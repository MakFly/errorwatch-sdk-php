<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Profiler;

use ErrorWatch\Sdk\Profiler\RequestProfile as SharedRequestProfile;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel-flavoured request profile bag.
 *
 * Inherits the framework-agnostic core ({@see SharedRequestProfile}) and adds
 * `start(Request)` which snapshots the incoming Laravel HTTP request + the
 * matched route into the shared bag.
 */
final class RequestProfile extends SharedRequestProfile
{
    /**
     * Snapshot the incoming Laravel request and start the profile.
     */
    public function start(Request $request): void
    {
        $this->startWithSnapshot(
            $this->snapshotRequest($request),
            $this->snapshotRoute($request),
        );
    }

    /**
     * Refresh the Laravel request/route snapshot without dropping collectors.
     */
    public function refresh(Request $request, ?Response $response = null): void
    {
        $this->refreshSnapshot(
            $this->snapshotRequest($request),
            $this->snapshotRoute($request),
            $response?->getStatusCode(),
        );
    }

    private function snapshotRequest(Request $request): array
    {
        $session = null;
        try {
            if ($request->hasSession()) {
                $sess = $request->session();
                $session = ['id' => $sess->getId(), 'data' => self::scrubSensitiveKeys($sess->all())];
            }
        } catch (\Throwable) {
            $session = null;
        }

        return [
            'ip' => $request->ip() ?? '',
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'path' => $request->path(),
            'query_string' => (string) ($request->getQueryString() ?? ''),
            'headers' => self::filterSensitiveHeaders($request->headers->all()),
            'content_type' => (string) $request->header('Content-Type', ''),
            'content_length' => (int) $request->header('Content-Length', 0),
            'cookies' => array_keys($request->cookies->all()),
            'session' => $session,
            'format' => $request->format(),
        ];
    }

    private function snapshotRoute(Request $request): ?array
    {
        $route = $request->route() ?? \Illuminate\Support\Facades\Route::current();
        if ($route === null) {
            return null;
        }

        try {
            $action = $route->getAction();
            $uses = $action['uses'] ?? null;
            $controller = $action['controller'] ?? null;

            return [
                'uri' => $route->uri(),
                'name' => $route->getName(),
                'action' => self::stringifyRouteAction($uses),
                'controller' => self::stringifyRouteAction($controller),
                'middleware' => array_values($route->gatherMiddleware()),
                'parameters' => self::scrubSensitiveKeys($route->parameters()),
                'methods' => $route->methods(),
                'domain' => $route->getDomain(),
                'prefix' => $route->getPrefix(),
                'wheres' => (array) ($route->wheres ?? []),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function stringifyRouteAction(mixed $action): ?string
    {
        if ($action === null) {
            return null;
        }

        if ($action instanceof \Closure) {
            return 'Closure';
        }

        if (is_string($action)) {
            return $action;
        }

        if (is_object($action)) {
            return $action::class;
        }

        if (is_scalar($action)) {
            return (string) $action;
        }

        return null;
    }
}
