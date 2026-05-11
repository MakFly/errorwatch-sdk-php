<?php

declare(strict_types=1);

namespace ErrorWatch\Symfony\EventSubscriber;

use ErrorWatch\Symfony\Http\MonitoringClientInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Drains pending async ErrorWatch requests after the HTTP response has been
 * sent. Under PHP-FPM / LiteSpeed, we also call fastcgi_finish_request() /
 * litespeed_finish_request() to let the client's TCP connection close
 * immediately — the SDK's drain runs in the now-detached worker.
 */
final class KernelTerminateSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly MonitoringClientInterface $client)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // High negative priority so we run after every other terminate
        // subscriber — the host app's post-response work goes first.
        return [
            KernelEvents::TERMINATE => ['onTerminate', -1024],
        ];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }

        $this->client->flushAsync();
    }
}
