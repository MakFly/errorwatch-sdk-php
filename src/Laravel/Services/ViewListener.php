<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Services;

use ErrorWatch\Laravel\Client\MonitoringClient;
use ErrorWatch\Laravel\Profiler\RequestProfile;
use Illuminate\Support\Facades\Event;
use Illuminate\View\Events\ViewRendered;

/**
 * Captures rendered Blade views into the per-request profile.
 *
 * Render time is approximated when not provided by the event.
 */
final class ViewListener
{
    public function __construct(
        private readonly MonitoringClient $client,
    ) {}

    public function register(): void
    {
        if (!class_exists(ViewRendered::class)) {
            return;
        }
        Event::listen(ViewRendered::class, [$this, 'onViewRendered']);
    }

    public function onViewRendered(ViewRendered $event): void
    {
        if (!$this->client->isEnabled() || !$this->client->getConfig('profiler.enabled', false)) {
            return;
        }
        try {
            $profile = app(RequestProfile::class);
            if (!$profile->isStarted()) {
                return;
            }
            $view = $event->view;
            $profile->recordView(
                $view->getName(),
                $view->getPath(),
                array_keys($view->getData()),
            );
        } catch (\Throwable) {
        }
    }
}
