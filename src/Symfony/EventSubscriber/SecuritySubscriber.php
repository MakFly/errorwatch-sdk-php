<?php

namespace ErrorWatch\Symfony\EventSubscriber;

use ErrorWatch\Symfony\Http\MonitoringClientInterface;
use ErrorWatch\Symfony\Model\Breadcrumb;
use ErrorWatch\Symfony\Service\BreadcrumbService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class SecuritySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly MonitoringClientInterface $client,
        private readonly ?BreadcrumbService $breadcrumbService = null,
        private readonly string $environment = 'prod',
        private readonly ?string $release = null,
        private readonly bool $captureLoginSuccess = false,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginFailureEvent::class => 'onLoginFailure',
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $exception = $event->getException();
        $request = $event->getRequest();

        $this->breadcrumbService?->add(Breadcrumb::user(
            'login_failure',
            sprintf('Login failed: %s', $exception->getMessage()),
        ));

        $payload = [
            'message' => sprintf('Login failure: %s', $exception->getMessage()),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'stack' => $exception->getTraceAsString(),
            'env' => $this->environment,
            'url' => $request?->getUri() ?? 'security://login',
            'level' => 'warning',
            'release' => $this->release,
            'created_at' => (int) (microtime(true) * 1000),
        ];

        $this->client->sendEventAsync($payload);
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if (!$this->captureLoginSuccess) {
            return;
        }

        $user = $event->getAuthenticatedToken()?->getUserIdentifier() ?? 'unknown';

        $this->breadcrumbService?->add(Breadcrumb::user(
            'login_success',
            sprintf('Login successful: %s', $user),
        ));
    }
}
