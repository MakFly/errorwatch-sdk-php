<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Http;

use ErrorWatch\Laravel\Client\MonitoringClient;
use ErrorWatch\Laravel\Tracing\Span;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Guzzle HandlerStack middleware that emits an `http.client` APM span for
 * every outgoing Guzzle request. Use it on any GuzzleHttp\Client
 * instantiated outside of Laravel's Http facade (e.g. third-party SDKs
 * like Stripe, Brevo, AWS, etc.):
 *
 *   $stack = \GuzzleHttp\HandlerStack::create();
 *   $stack->push(GuzzleTracingMiddleware::create());
 *   $client = new \GuzzleHttp\Client(['handler' => $stack]);
 *
 * The middleware reads the current MonitoringClient transaction from the
 * service container — no per-call wiring needed. If no transaction is
 * active (e.g. CLI without a parent span), the request passes through
 * untouched.
 */
final class GuzzleTracingMiddleware
{
    public static function create(?MonitoringClient $client = null): callable
    {
        return static function (callable $handler) use ($client): callable {
            return static function (RequestInterface $request, array $options) use ($handler, $client) {
                $monitoring = $client ?? self::resolveClient();

                if ($monitoring === null || !$monitoring->isEnabled()) {
                    return $handler($request, $options);
                }

                $transaction = $monitoring->getCurrentTransaction();
                if (!$transaction instanceof Span) {
                    return $handler($request, $options);
                }

                $method = $request->getMethod();
                $url    = (string) $request->getUri();
                $host   = $request->getUri()->getHost();

                $span = $transaction->startChild(
                    sprintf('%s %s', $method, $url),
                    'http.client',
                );
                $span->setTag('http.method', $method);
                $span->setTag('http.url', $url);
                $span->setTag('http.host', $host);

                return $handler($request, $options)->then(
                    static function (ResponseInterface $response) use ($span): ResponseInterface {
                        $status = $response->getStatusCode();
                        $span->setTag('http.status_code', $status);

                        if ($status >= 400) {
                            $span->setError("HTTP {$status}");
                        } else {
                            $span->setOk();
                        }
                        $span->finish();

                        return $response;
                    },
                    static function (Throwable $reason) use ($span) {
                        $span->setError($reason->getMessage());
                        $span->finish();

                        return Create::rejectionFor($reason);
                    },
                );
            };
        };
    }

    /**
     * Resolve the MonitoringClient from the Laravel container if available,
     * else return null. Lazy-resolved per request so the middleware works
     * even before the container is fully booted at construction time.
     */
    private static function resolveClient(): ?MonitoringClient
    {
        if (!function_exists('app')) {
            return null;
        }
        try {
            $instance = app(MonitoringClient::class);
            return $instance instanceof MonitoringClient ? $instance : null;
        } catch (Throwable) {
            return null;
        }
    }
}
