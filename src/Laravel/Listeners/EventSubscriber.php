<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Listeners;

use ErrorWatch\Laravel\Client\MonitoringClient;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Events\Dispatcher;

class EventSubscriber
{
    protected MonitoringClient $client;

    public function __construct(MonitoringClient $client)
    {
        $this->client = $client;
    }

    /**
     * Subscribe to Laravel events.
     */
    public function subscribe(Dispatcher $events): array
    {
        $listeners = [];

        // Auth events
        if ($this->client->getConfig('security.enabled', true)) {
            $listeners[Login::class] = 'onUserLogin';
            $listeners[Logout::class] = 'onUserLogout';
            $listeners[Failed::class] = 'onAuthFailed';

            if ($this->client->getConfig('security.capture_login_success', false)) {
                $listeners[Registered::class] = 'onUserRegistered';
                $listeners[Verified::class] = 'onUserVerified';
                $listeners[PasswordReset::class] = 'onPasswordReset';
            }
        }

        // Console events
        if ($this->client->getConfig('console.enabled', true)) {
            $listeners[CommandStarting::class] = 'onCommandStarting';
            $listeners[CommandFinished::class] = 'onCommandFinished';
        }

        return $listeners;
    }

    /**
     * Handle user login.
     */
    public function onUserLogin(Login $event): void
    {
        if (!$this->client->isEnabled()) {
            return;
        }

        // Set user context
        $user = $event->user;
        $userData = [
            'id' => $user->getAuthIdentifier(),
        ];

        if (isset($user->email)) {
            $userData['email'] = $user->email;
        }

        if (isset($user->name)) {
            $userData['username'] = $user->name;
        }

        $this->client->setUser($userData);

        // Add security breadcrumb
        if ($this->client->getConfig('breadcrumbs.enabled', true)) {
            $this->client->getBreadcrumbManager()->addSecurity('login', [
                'remember' => $event->remember ?? false,
                'guard' => $event->guard ?? 'web',
            ]);
        }
    }

    /**
     * Handle user logout.
     */
    public function onUserLogout(Logout $event): void
    {
        if (!$this->client->isEnabled()) {
            return;
        }

        // Add security breadcrumb before clearing user
        if ($this->client->getConfig('breadcrumbs.enabled', true)) {
            $this->client->getBreadcrumbManager()->addSecurity('logout');
        }

        // Clear user context
        $this->client->clearUser();
    }

    /**
     * Handle authentication failure.
     */
    public function onAuthFailed(Failed $event): void
    {
        if (!$this->client->isEnabled()) {
            return;
        }

        // Add security breadcrumb
        if ($this->client->getConfig('breadcrumbs.enabled', true)) {
            $this->client->getBreadcrumbManager()->addSecurity('auth_failed', [
                'guard' => $event->guard ?? 'web',
            ]);
        }

        // Capture as a message
        $this->client->captureMessage(
            'Authentication failed',
            'warning',
            [
                'extra' => [
                    'guard' => $event->guard ?? 'web',
                    'credentials' => array_keys($event->credentials ?? []),
                ],
                'tags' => [
                    'security_event' => 'auth_failed',
                ],
            ]
        );
    }

    /**
     * Handle user registration.
     */
    public function onUserRegistered(Registered $event): void
    {
        if (!$this->client->isEnabled()) {
            return;
        }

        $this->client->getBreadcrumbManager()->addSecurity('registered', [
            'user_id' => $event->user->getAuthIdentifier(),
        ]);
    }

    /**
     * Handle email verification.
     */
    public function onUserVerified(Verified $event): void
    {
        if (!$this->client->isEnabled()) {
            return;
        }

        $this->client->getBreadcrumbManager()->addSecurity('verified', [
            'user_id' => $event->user->getAuthIdentifier(),
        ]);
    }

    /**
     * Handle password reset.
     */
    public function onPasswordReset(PasswordReset $event): void
    {
        if (!$this->client->isEnabled()) {
            return;
        }

        $this->client->getBreadcrumbManager()->addSecurity('password_reset', [
            'user_id' => $event->user->getAuthIdentifier(),
        ]);
    }

    /**
     * Handle console command starting.
     */
    public function onCommandStarting(CommandStarting $event): void
    {
        if (!$this->client->isEnabled()) {
            return;
        }

        $command = $event->command ?? 'unknown';

        // Add breadcrumb
        if ($this->client->getConfig('breadcrumbs.enabled', true)) {
            $this->client->getBreadcrumbManager()->addConsole($command, [
                'arguments' => $event->input->getArguments(),
                'options' => $this->sanitizeOptions($event->input->getOptions()),
            ]);
        }
    }

    /**
     * Handle console command finished.
     */
    public function onCommandFinished(CommandFinished $event): void
    {
        if (!$this->client->isEnabled()) {
            return;
        }

        $command = $event->command ?? 'unknown';
        $exitCode = $event->exitCode ?? 0;

        // Capture non-zero exit codes
        if ($exitCode !== 0 && $this->client->getConfig('console.capture_exit_codes', true)) {
            $this->client->captureMessage(
                "Command '{$command}' exited with code {$exitCode}",
                'warning',
                [
                    'extra' => [
                        'command' => $command,
                        'exit_code' => $exitCode,
                        'arguments' => $event->input->getArguments(),
                    ],
                    'tags' => [
                        'console_command' => $command,
                    ],
                ]
            );
        }

        // Add breadcrumb for completion
        if ($this->client->getConfig('breadcrumbs.enabled', true)) {
            $this->client->getBreadcrumbManager()->addConsole(
                $command,
                [],
                $exitCode
            );
        }
    }

    /**
     * Sanitize command options (remove sensitive values).
     */
    protected function sanitizeOptions(array $options): array
    {
        $sensitiveOptions = ['password', 'secret', 'token', 'key', 'api-key'];

        return array_map(function ($key, $value) use ($sensitiveOptions) {
            foreach ($sensitiveOptions as $sensitive) {
                if (str_contains(strtolower($key), $sensitive)) {
                    return '[redacted]';
                }
            }

            return $value;
        }, array_keys($options), $options);
    }
}
