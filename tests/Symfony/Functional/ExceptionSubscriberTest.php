<?php

namespace ErrorWatch\Symfony\Tests\Functional;

use ErrorWatch\Sdk\Client;
use ErrorWatch\Sdk\Options;
use ErrorWatch\Sdk\Transport\TransportInterface;
use ErrorWatch\Symfony\EventSubscriber\ExceptionSubscriber;
use ErrorWatch\Symfony\Model\Breadcrumb;
use ErrorWatch\Symfony\Service\BreadcrumbService;
use ErrorWatch\Symfony\Service\UserContextService;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\User\UserInterface;

final class ExceptionSubscriberTest extends TestCase
{
    private function makeClient(?TransportInterface $transport = null): Client
    {
        return new Client(
            new Options([
                'endpoint' => 'http://localhost',
                'api_key'  => 'test-key',
                'enabled'  => true,
            ]),
            $transport,
        );
    }

    public function testCapturesExceptionAndSendsToServer(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects($this->once())->method('send')->willReturn(true);

        $subscriber = new ExceptionSubscriber($this->makeClient($transport));
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($subscriber);

        $request = Request::create('/test/endpoint');
        $mockKernel = $this->createMock(HttpKernelInterface::class);

        $event = new ExceptionEvent(
            $mockKernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('Test exception')
        );

        $dispatcher->dispatch($event, KernelEvents::EXCEPTION);
    }

    public function testRegistersCorrectEvent(): void
    {
        $subscriber = new ExceptionSubscriber($this->makeClient());

        $subscribedEvents = $subscriber->getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::EXCEPTION, $subscribedEvents);
        $this->assertSame('onException', $subscribedEvents[KernelEvents::EXCEPTION]);
    }

    public function testSendsWithBreadcrumbs(): void
    {
        $breadcrumbService = new BreadcrumbService(100);
        $breadcrumbService->add(Breadcrumb::http('GET', '/api/users'));
        $breadcrumbService->add(Breadcrumb::user('click', 'Submit button clicked'));

        $transport = $this->createMock(TransportInterface::class);
        $transport->expects($this->once())
            ->method('send')
            ->with($this->callback(function (array $payload) {
                return isset($payload['breadcrumbs'])
                    && 2 === count($payload['breadcrumbs'])
                    && 'http' === $payload['breadcrumbs'][0]['category'];
            }))
            ->willReturn(true);

        $subscriber = new ExceptionSubscriber(
            $this->makeClient($transport),
            $breadcrumbService,
            null,
            true,
            false
        );

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($subscriber);

        $request = Request::create('/test');
        $mockKernel = $this->createMock(HttpKernelInterface::class);
        $event = new ExceptionEvent(
            $mockKernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('Test')
        );

        $dispatcher->dispatch($event, KernelEvents::EXCEPTION);
    }

    public function testSendsWithUserContext(): void
    {
        $user = new class implements UserInterface {
            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'user123';
            }

            public function getEmail(): string
            {
                return 'test@example.com';
            }
        };

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $requestStack = new RequestStack();
        $request = Request::create('/test', 'GET', [], [], [], ['REMOTE_ADDR' => '192.168.1.1']);
        $requestStack->push($request);

        $userContextService = new UserContextService($security, $requestStack, true);

        $transport = $this->createMock(TransportInterface::class);
        $transport->expects($this->once())
            ->method('send')
            ->with($this->callback(function (array $payload) {
                return isset($payload['user'])
                    && 'user123' === $payload['user']['id']
                    && 'test@example.com' === $payload['user']['email']
                    && '192.168.1.1' === $payload['user']['ip_address'];
            }))
            ->willReturn(true);

        $subscriber = new ExceptionSubscriber(
            $this->makeClient($transport),
            null,
            $userContextService,
            false,
            true
        );

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($subscriber);

        $mockKernel = $this->createMock(HttpKernelInterface::class);
        $event = new ExceptionEvent(
            $mockKernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('Test')
        );

        $dispatcher->dispatch($event, KernelEvents::EXCEPTION);
    }

    public function testSendsWithFullContext(): void
    {
        // Setup breadcrumbs
        $breadcrumbService = new BreadcrumbService(100);
        $breadcrumbService->add(Breadcrumb::navigation('/home', '/about'));

        // Setup user context
        $user = new class implements UserInterface {
            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'user456';
            }
        };
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $requestStack = new RequestStack();
        $request = Request::create('/about');
        $requestStack->push($request);

        $userContextService = new UserContextService($security, $requestStack, false);

        $transport = $this->createMock(TransportInterface::class);
        $transport->expects($this->once())
            ->method('send')
            ->with($this->callback(function (array $payload) {
                return isset($payload['breadcrumbs'])
                    && isset($payload['user'])
                    && 'user456' === $payload['user']['id']
                    && 1 === count($payload['breadcrumbs']);
            }))
            ->willReturn(true);

        $subscriber = new ExceptionSubscriber(
            $this->makeClient($transport),
            $breadcrumbService,
            $userContextService,
            true,
            true
        );

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($subscriber);

        $mockKernel = $this->createMock(HttpKernelInterface::class);
        $event = new ExceptionEvent(
            $mockKernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('Full context test')
        );

        $dispatcher->dispatch($event, KernelEvents::EXCEPTION);
    }

    public function testDisablesBreadcrumbsWhenConfigured(): void
    {
        $breadcrumbService = new BreadcrumbService(100);
        $breadcrumbService->add(Breadcrumb::user('click', 'Test'));

        $transport = $this->createMock(TransportInterface::class);
        $transport->expects($this->once())
            ->method('send')
            ->with($this->callback(function (array $payload) {
                // Breadcrumbs should NOT be included
                return !isset($payload['breadcrumbs']);
            }))
            ->willReturn(true);

        // breadcrumbsEnabled = false
        $subscriber = new ExceptionSubscriber(
            $this->makeClient($transport),
            $breadcrumbService,
            null,
            false,
            false
        );

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($subscriber);

        $request = Request::create('/test');
        $mockKernel = $this->createMock(HttpKernelInterface::class);
        $event = new ExceptionEvent(
            $mockKernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('Test')
        );

        $dispatcher->dispatch($event, KernelEvents::EXCEPTION);
    }
}
