<?php

namespace ErrorWatch\Symfony\EventSubscriber;

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
        if (!$this->enabled || !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
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
    }
}
