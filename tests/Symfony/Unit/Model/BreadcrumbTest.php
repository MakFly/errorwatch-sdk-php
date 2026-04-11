<?php

namespace ErrorWatch\Symfony\Tests\Unit\Model;

use ErrorWatch\Symfony\Model\Breadcrumb;
use PHPUnit\Framework\TestCase;

final class BreadcrumbTest extends TestCase
{
    public function testConstructorCreatesBreadcrumb(): void
    {
        $timestamp = time();
        $breadcrumb = new Breadcrumb(
            category: 'user',
            timestamp: $timestamp,
            type: 'default',
            level: 'info',
            message: 'Test message',
            data: ['key' => 'value']
        );

        $this->assertSame('user', $breadcrumb->category);
        $this->assertSame($timestamp, $breadcrumb->timestamp);
        $this->assertSame('default', $breadcrumb->type);
        $this->assertSame('info', $breadcrumb->level);
        $this->assertSame('Test message', $breadcrumb->message);
        $this->assertSame(['key' => 'value'], $breadcrumb->data);
    }

    public function testToArrayFiltersNullValues(): void
    {
        $timestamp = time();
        $breadcrumb = new Breadcrumb(
            category: 'user',
            timestamp: $timestamp
        );

        $array = $breadcrumb->toArray();

        $this->assertArrayHasKey('timestamp', $array);
        $this->assertArrayHasKey('category', $array);
        $this->assertArrayNotHasKey('type', $array);
        $this->assertArrayNotHasKey('level', $array);
        $this->assertArrayNotHasKey('message', $array);
        $this->assertArrayNotHasKey('data', $array);
    }

    public function testToArrayIncludesAllNonNullValues(): void
    {
        $timestamp = time();
        $breadcrumb = new Breadcrumb(
            category: 'http',
            timestamp: $timestamp,
            type: 'http',
            level: 'info',
            message: 'GET /api/test',
            data: ['method' => 'GET']
        );

        $array = $breadcrumb->toArray();

        $this->assertSame($timestamp, $array['timestamp']);
        $this->assertSame('http', $array['category']);
        $this->assertSame('http', $array['type']);
        $this->assertSame('info', $array['level']);
        $this->assertSame('GET /api/test', $array['message']);
        $this->assertSame(['method' => 'GET'], $array['data']);
    }

    public function testHttpFactoryMethod(): void
    {
        $breadcrumb = Breadcrumb::http('GET', '/api/users', 200);

        $this->assertSame(Breadcrumb::CATEGORY_HTTP, $breadcrumb->category);
        $this->assertSame(Breadcrumb::TYPE_HTTP, $breadcrumb->type);
        $this->assertSame('GET /api/users', $breadcrumb->message);

        $array = $breadcrumb->toArray();
        $this->assertSame('GET', $array['data']['method']);
        $this->assertSame('/api/users', $array['data']['url']);
        $this->assertSame(200, $array['data']['status_code']);
    }

    public function testHttpFactoryMethodWithZeroStatusCode(): void
    {
        $breadcrumb = Breadcrumb::http('POST', '/api/users', 0);

        $array = $breadcrumb->toArray();
        $this->assertNull($array['data']['status_code'] ?? null);
    }

    public function testNavigationFactoryMethod(): void
    {
        $breadcrumb = Breadcrumb::navigation('/home', '/about');

        $this->assertSame(Breadcrumb::CATEGORY_NAVIGATION, $breadcrumb->category);
        $this->assertSame(Breadcrumb::TYPE_NAVIGATION, $breadcrumb->type);
        $this->assertStringContainsString('/home', $breadcrumb->message);
        $this->assertStringContainsString('/about', $breadcrumb->message);

        $array = $breadcrumb->toArray();
        $this->assertSame('/home', $array['data']['from']);
        $this->assertSame('/about', $array['data']['to']);
    }

    public function testUserFactoryMethod(): void
    {
        $breadcrumb = Breadcrumb::user('click', 'Clicked submit button', ['element' => 'button']);

        $this->assertSame(Breadcrumb::CATEGORY_USER, $breadcrumb->category);
        $this->assertSame(Breadcrumb::TYPE_USER, $breadcrumb->type);
        $this->assertSame('Clicked submit button', $breadcrumb->message);

        $array = $breadcrumb->toArray();
        $this->assertSame('click', $array['data']['action']);
        $this->assertSame('button', $array['data']['element']);
    }

    public function testLogFactoryMethod(): void
    {
        $breadcrumb = Breadcrumb::log('error', 'Database connection failed', ['host' => 'localhost']);

        $this->assertSame(Breadcrumb::CATEGORY_CONSOLE, $breadcrumb->category);
        $this->assertSame(Breadcrumb::TYPE_INFO, $breadcrumb->type);
        $this->assertSame('error', $breadcrumb->level);
        $this->assertSame('Database connection failed', $breadcrumb->message);

        $array = $breadcrumb->toArray();
        $this->assertSame('localhost', $array['data']['host']);
    }

    public function testUiFactoryMethod(): void
    {
        $breadcrumb = Breadcrumb::ui('#submit-button', 'click');

        $this->assertSame(Breadcrumb::CATEGORY_UI, $breadcrumb->category);
        $this->assertSame(Breadcrumb::TYPE_USER, $breadcrumb->type);

        $array = $breadcrumb->toArray();
        $this->assertSame('#submit-button', $array['data']['element']);
        $this->assertSame('click', $array['data']['action']);
    }
}
