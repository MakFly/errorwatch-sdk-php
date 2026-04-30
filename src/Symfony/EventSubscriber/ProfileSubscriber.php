<?php

declare(strict_types=1);

namespace ErrorWatch\Symfony\EventSubscriber;

use ErrorWatch\Symfony\Profiler\RequestProfile;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Bootstraps the per-request profile bag at the very start of every main
 * request. Other Symfony collectors (Doctrine, cache, mail, http client,
 * messenger, monolog) push records into the bag during the request.
 *
 * The bag is read by {@see ExceptionSubscriber} when an exception is captured
 * and attached to the outgoing event payload as `profile`.
 */
final class ProfileSubscriber implements EventSubscriberInterface
{
    private readonly bool $enabled;

    public function __construct(
        private readonly RequestProfile $profile,
        mixed $enabled,
    ) {
        $this->enabled = (bool) filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 16 — fire AFTER Symfony's RouterListener (priority 32) so
        // `_route` and `_route_params` attributes are already populated when
        // we snapshot. All Doctrine/cache/HTTP collectors run later (during
        // the controller execution) and push into the bag we start here.
        return [
            KernelEvents::REQUEST => ['onRequest', 16],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest()) {
            return;
        }

        try {
            $this->profile->start($event->getRequest());
        } catch (\Throwable) {
            // Never break the request from inside the profiler.
        }
    }
}
