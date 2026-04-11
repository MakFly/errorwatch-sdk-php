<?php

namespace ErrorWatch\Laravel\Tests\Unit;

use ErrorWatch\Laravel\Context\UserContext;
use ErrorWatch\Laravel\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class UserContextTest extends TestCase
{
    protected UserContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = new UserContext();
    }

    #[Test]
    public function it_can_set_user_from_array(): void
    {
        $this->context->setUser([
            'id' => 123,
            'email' => 'test@example.com',
            'username' => 'testuser',
            'extra' => 'data',
        ]);

        $user = $this->context->getUser();

        $this->assertEquals(123, $user['id']);
        $this->assertEquals('test@example.com', $user['email']);
        $this->assertEquals('testuser', $user['username']);
    }

    #[Test]
    public function it_can_set_user_from_object(): void
    {
        $user = new \stdClass();
        $user->id = 456;
        $user->email = 'object@example.com';
        $user->name = 'Object User';

        $this->context->setUser($user);

        $context = $this->context->getUser();

        $this->assertEquals(456, $context['id']);
        $this->assertEquals('object@example.com', $context['email']);
        $this->assertEquals('Object User', $context['username']);
    }

    #[Test]
    public function it_can_clear_user(): void
    {
        $this->context->setUser(['id' => 123]);
        $this->context->clearUser();

        $this->assertNull($this->context->getUser());
    }

    #[Test]
    public function it_can_set_ip_address(): void
    {
        $this->context->setUser(['id' => 123]);
        $this->context->setIpAddress('192.168.1.1');

        $user = $this->context->getUser();

        $this->assertEquals('192.168.1.1', $user['ip_address']);
    }

    #[Test]
    public function it_can_set_extra_data(): void
    {
        $this->context->setUser(['id' => 123]);
        $this->context->setExtra(['role' => 'admin', 'team' => 'engineering']);

        $user = $this->context->getUser();

        $this->assertEquals([
            'role' => 'admin',
            'team' => 'engineering',
        ], $user['extra']);
    }

    #[Test]
    public function it_returns_null_when_no_user_set(): void
    {
        $this->assertNull($this->context->getUser());
        $this->assertNull($this->context->getId());
        $this->assertNull($this->context->getEmail());
    }
}
