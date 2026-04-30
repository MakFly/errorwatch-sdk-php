<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Services;

use ErrorWatch\Laravel\Client\MonitoringClient;
use ErrorWatch\Laravel\Profiler\RequestProfile;
use Illuminate\Support\Facades\Gate;

/**
 * Captures Gate authorization checks into the per-request profile.
 *
 * Uses Gate::after() to observe every check after it has been resolved.
 */
final class GateListener
{
    public function __construct(
        private readonly MonitoringClient $client,
    ) {}

    public function register(): void
    {
        Gate::after(function ($user, string $ability, ?bool $result, array $arguments) {
            $this->record($user, $ability, $result, $arguments);
            return $result;
        });
    }

    private function record($user, string $ability, ?bool $result, array $arguments): void
    {
        if (!$this->client->isEnabled() || !$this->client->getConfig('profiler.enabled', false)) {
            return;
        }
        try {
            $profile = app(RequestProfile::class);
            if (!$profile->isStarted()) {
                return;
            }
            $userId = is_object($user) && method_exists($user, 'getAuthIdentifier')
                ? (string) $user->getAuthIdentifier()
                : null;
            $profile->recordGate($ability, (bool) $result, $userId, $arguments);
        } catch (\Throwable) {
        }
    }
}
