<?php

namespace ErrorWatch\Symfony\Tests\Unit\EventSubscriber;

use ErrorWatch\Symfony\EventSubscriber\SecuritySubscriber;
use ErrorWatch\Symfony\Http\MonitoringClientInterface;
use ErrorWatch\Symfony\Service\BreadcrumbService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class SecuritySubscriberTest extends TestCase
{
    private MonitoringClientInterface $client;
    private BreadcrumbService $breadcrumbService;

    protected function setUp(): void
    {
        $this->client = $this->createMock(MonitoringClientInterface::class);
        $this->breadcrumbService = new BreadcrumbService();
    }

    public function testOnLoginFailureSendsWarningEvent(): void
    {
        $exception = new AuthenticationException('Invalid credentials');
        $request = Request::create('/login', 'POST');

        $event = $this->createMock(LoginFailureEvent::class);
        $event->method('getException')->willReturn($exception);
        $event->method('getRequest')->willReturn($request);

        $this->client->expects($this->once())
            ->method('sendEventAsync')
            ->with($this->callback(function (array $payload) {
                return 'warning' === $payload['level']
                    && str_contains($payload['message'], 'Login failure')
                    && str_contains($payload['message'], 'Invalid credentials')
                    && 'test' === $payload['env'];
            }));

        $subscriber = new SecuritySubscriber(
            client: $this->client,
            breadcrumbService: $this->breadcrumbService,
            environment: 'test',
        );

        $subscriber->onLoginFailure($event);
    }

    public function testOnLoginFailureAddsBreadcrumb(): void
    {
        $exception = new AuthenticationException('Invalid credentials');
        $request = Request::create('/login', 'POST');

        $event = $this->createMock(LoginFailureEvent::class);
        $event->method('getException')->willReturn($exception);
        $event->method('getRequest')->willReturn($request);

        $subscriber = new SecuritySubscriber(
            client: $this->client,
            breadcrumbService: $this->breadcrumbService,
            environment: 'test',
        );

        $subscriber->onLoginFailure($event);

        $breadcrumbs = $this->breadcrumbService->all();
        $this->assertCount(1, $breadcrumbs);
        $this->assertSame('user', $breadcrumbs[0]['category']);
        $this->assertStringContainsString('Login failed', $breadcrumbs[0]['message']);
    }

    public function testOnLoginSuccessAddsBreadcrumbWhenEnabled(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUserIdentifier')->willReturn('user@test.com');

        $event = $this->createMock(LoginSuccessEvent::class);
        $event->method('getAuthenticatedToken')->willReturn($token);

        $subscriber = new SecuritySubscriber(
            client: $this->client,
            breadcrumbService: $this->breadcrumbService,
            environment: 'test',
            captureLoginSuccess: true,
        );

        $subscriber->onLoginSuccess($event);

        $breadcrumbs = $this->breadcrumbService->all();
        $this->assertCount(1, $breadcrumbs);
        $this->assertSame('user', $breadcrumbs[0]['category']);
        $this->assertStringContainsString('Login successful', $breadcrumbs[0]['message']);
        $this->assertStringContainsString('user@test.com', $breadcrumbs[0]['message']);
    }

    public function testOnLoginSuccessDoesNothingWhenDisabled(): void
    {
        $event = $this->createMock(LoginSuccessEvent::class);
        $event->expects($this->never())->method('getAuthenticatedToken');

        $subscriber = new SecuritySubscriber(
            client: $this->client,
            breadcrumbService: $this->breadcrumbService,
            environment: 'test',
            captureLoginSuccess: false,
        );

        $subscriber->onLoginSuccess($event);

        $this->assertTrue($this->breadcrumbService->isEmpty());
    }

    public function testGetSubscribedEventsReturnsCorrectEvents(): void
    {
        $events = SecuritySubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(LoginFailureEvent::class, $events);
        $this->assertArrayHasKey(LoginSuccessEvent::class, $events);
        $this->assertSame('onLoginFailure', $events[LoginFailureEvent::class]);
        $this->assertSame('onLoginSuccess', $events[LoginSuccessEvent::class]);
    }
}
