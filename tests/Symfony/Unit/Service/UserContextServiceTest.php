<?php

namespace ErrorWatch\Symfony\Tests\Unit\Service;

use ErrorWatch\Symfony\Service\UserContextService;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserContextServiceTest extends TestCase
{
    public function testGetContextWithNoUserAndNoRequest(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $requestStack = new RequestStack();

        $service = new UserContextService($security, $requestStack, true);

        $this->assertNull($service->getContext());
    }

    public function testGetContextWithRequestOnly(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $requestStack = new RequestStack();
        $request = Request::create('/test', 'GET', [], [], [], ['REMOTE_ADDR' => '192.168.1.1']);
        $requestStack->push($request);

        $service = new UserContextService($security, $requestStack, true);

        $context = $service->getContext();

        $this->assertNotNull($context);
        $this->assertArrayHasKey('ip_address', $context);
        $this->assertSame('192.168.1.1', $context['ip_address']);
    }

    public function testGetContextWithRequestButIpCaptureDisabled(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $requestStack = new RequestStack();
        $request = Request::create('/test', 'GET', [], [], [], ['REMOTE_ADDR' => '192.168.1.1']);
        $requestStack->push($request);

        $service = new UserContextService($security, $requestStack, false);

        $this->assertNull($service->getContext());
    }

    public function testGetContextWithUser(): void
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

        $service = new UserContextService($security, $requestStack, false);

        $context = $service->getContext();

        $this->assertNotNull($context);
        $this->assertSame('user123', $context['id']);
        $this->assertSame('test@example.com', $context['email']);
    }

    public function testGetContextWithUserHavingEmailAsIdentifier(): void
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
                return 'user@example.com';
            }
        };

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $requestStack = new RequestStack();

        $service = new UserContextService($security, $requestStack, false);

        $context = $service->getContext();

        $this->assertNotNull($context);
        $this->assertSame('user@example.com', $context['id']);
        $this->assertSame('user@example.com', $context['email']);
    }

    public function testGetContextWithNullSecurity(): void
    {
        $requestStack = new RequestStack();
        $request = Request::create('/test', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.1']);
        $requestStack->push($request);

        $service = new UserContextService(null, $requestStack, true);

        $context = $service->getContext();

        $this->assertNotNull($context);
        $this->assertSame('10.0.0.1', $context['ip_address']);
    }

    public function testIsAvailableWithSecurity(): void
    {
        $security = $this->createMock(Security::class);
        $requestStack = new RequestStack();

        $service = new UserContextService($security, $requestStack, true);

        $this->assertTrue($service->isAvailable());
    }

    public function testIsAvailableWithRequest(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/test'));

        $service = new UserContextService(null, $requestStack, true);

        $this->assertTrue($service->isAvailable());
    }

    public function testIsAvailableWithNothing(): void
    {
        $requestStack = new RequestStack();

        $service = new UserContextService(null, $requestStack, true);

        $this->assertFalse($service->isAvailable());
    }

    public function testGetContextWithUserAndRequest(): void
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
                return 'user456';
            }

            public function getUsername(): string
            {
                return 'testuser';
            }
        };

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $requestStack = new RequestStack();
        $request = Request::create('/test', 'GET', [], [], [], ['REMOTE_ADDR' => '192.168.1.100']);
        $requestStack->push($request);

        $service = new UserContextService($security, $requestStack, true);

        $context = $service->getContext();

        $this->assertNotNull($context);
        $this->assertSame('user456', $context['id']);
        $this->assertSame('testuser', $context['username']);
        $this->assertSame('192.168.1.100', $context['ip_address']);
    }
}
