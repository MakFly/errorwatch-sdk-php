<?php

namespace ErrorWatch\Symfony\EventSubscriber;

use ErrorWatch\Sdk\Tracing\TraceContext;
use ErrorWatch\Symfony\Model\Breadcrumb;
use ErrorWatch\Symfony\Service\BreadcrumbService;
use ErrorWatch\Symfony\Service\TransactionCollector;
use ErrorWatch\Symfony\Service\TransactionSender;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class RequestSubscriber implements EventSubscriberInterface
{
    /**
     * @param string[] $excludedRoutes
     */
    public function __construct(
        private readonly TransactionCollector $collector,
        private readonly TransactionSender $sender,
        private readonly bool $enabled,
        private readonly array $excludedRoutes = [],
        private readonly ?BreadcrumbService $breadcrumbService = null,
        private readonly ?TraceContext $traceContext = null,
        private readonly bool $tracingEnabled = true,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 4096],
            KernelEvents::TERMINATE => ['onTerminate', -4096],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Start the trace context first so any log/error emitted during
        // this request carries a trace id, regardless of APM enabled state.
        if ($this->tracingEnabled && null !== $this->traceContext) {
            $parsed = TraceContext::parseTraceparent($request->headers->get('traceparent'));
            if (null !== $parsed) {
                $this->traceContext->start($parsed['traceId'], $parsed['spanId'], $parsed['sampled']);
            } else {
                $this->traceContext->start();
            }
        }

        if (!$this->enabled) {
            return;
        }

        $route = $request->attributes->get('_route', '');

        if (in_array($route, $this->excludedRoutes, true)) {
            return;
        }

        $method = $request->getMethod();
        $pathInfo = $request->getPathInfo();

        $this->breadcrumbService?->add(Breadcrumb::http($method, $pathInfo));

        $name = $method.' '.($route ?: $pathInfo);
        $txn = $this->collector->startTransaction($name, 'http.server');
        $txn->setTag('http.method', $method);
        $txn->setData('http.url', $pathInfo);
    }

    public function onTerminate(TerminateEvent $event): void
    {
        try {
            if (!$this->collector->hasTransaction()) {
                return;
            }

            $response = $event->getResponse();
            $statusCode = $response->getStatusCode();

            $status = $statusCode >= 500 ? 'error' : 'ok';
            $txn = $this->collector->finishTransaction($status);

            if (null === $txn) {
                return;
            }

            $txn->setTag('http.status_code', (string) $statusCode);
            $this->sender->send($txn);
        } finally {
            // Release the trace context even if anything above throws so
            // long-running workers don't leak state across requests.
            $this->traceContext?->reset();
        }
    }
}
