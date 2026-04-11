<?php

namespace ErrorWatch\Laravel\Tests\Unit;

use ErrorWatch\Laravel\Breadcrumbs\BreadcrumbManager;
use ErrorWatch\Laravel\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class BreadcrumbManagerTest extends TestCase
{
    protected BreadcrumbManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new BreadcrumbManager(100);
    }

    #[Test]
    public function it_can_add_breadcrumb(): void
    {
        $this->manager->add('Test message', 'info', 'default', ['key' => 'value']);

        $breadcrumbs = $this->manager->all();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('Test message', $breadcrumbs[0]['message']);
        $this->assertEquals('info', $breadcrumbs[0]['type']);
        $this->assertEquals(['key' => 'value'], $breadcrumbs[0]['data']);
    }

    #[Test]
    public function it_limits_breadcrumb_count(): void
    {
        $manager = new BreadcrumbManager(5);

        for ($i = 0; $i < 10; $i++) {
            $manager->add("Breadcrumb {$i}");
        }

        $breadcrumbs = $manager->all();

        $this->assertCount(5, $breadcrumbs);
        // Should keep the last 5
        $this->assertEquals('Breadcrumb 5', $breadcrumbs[0]['message']);
        $this->assertEquals('Breadcrumb 9', $breadcrumbs[4]['message']);
    }

    #[Test]
    public function it_can_add_request_breadcrumb(): void
    {
        $this->manager->addRequest('GET', '/api/users', 200);

        $breadcrumb = $this->manager->all()[0];

        $this->assertEquals('HTTP GET /api/users', $breadcrumb['message']);
        $this->assertEquals('http', $breadcrumb['type']);
        $this->assertEquals('GET', $breadcrumb['data']['method']);
        $this->assertEquals(200, $breadcrumb['data']['status_code']);
    }

    #[Test]
    public function it_can_add_query_breadcrumb(): void
    {
        $this->manager->addQuery('SELECT * FROM users', 15.5, 'mysql');

        $breadcrumb = $this->manager->all()[0];

        $this->assertStringContainsString('Query', $breadcrumb['message']);
        $this->assertEquals('query', $breadcrumb['type']);
        $this->assertEquals(15.5, $breadcrumb['data']['duration_ms']);
    }

    #[Test]
    public function it_can_add_http_client_breadcrumb(): void
    {
        $this->manager->addHttp('POST', 'https://api.example.com/users', 201, 150);

        $breadcrumb = $this->manager->all()[0];

        $this->assertEquals('http_client', $breadcrumb['category']);
        $this->assertEquals(201, $breadcrumb['data']['status_code']);
        $this->assertEquals(150, $breadcrumb['data']['duration_ms']);
    }

    #[Test]
    public function it_can_add_console_breadcrumb(): void
    {
        $this->manager->addConsole('migrate', ['--force' => true], 0);

        $breadcrumb = $this->manager->all()[0];

        $this->assertEquals('console', $breadcrumb['type']);
        $this->assertEquals('migrate', $breadcrumb['data']['command']);
        $this->assertEquals(0, $breadcrumb['data']['exit_code']);
    }

    #[Test]
    public function it_can_add_security_breadcrumb(): void
    {
        $this->manager->addSecurity('login', ['user_id' => 123]);

        $breadcrumb = $this->manager->all()[0];

        $this->assertEquals('security', $breadcrumb['type']);
        $this->assertEquals('auth', $breadcrumb['category']);
        $this->assertEquals('login', $breadcrumb['data']['event']);
    }

    #[Test]
    public function it_can_clear_breadcrumbs(): void
    {
        $this->manager->add('Test 1');
        $this->manager->add('Test 2');

        $this->manager->clear();

        $this->assertEmpty($this->manager->all());
    }

    #[Test]
    public function it_can_change_max_count(): void
    {
        $this->manager->setMaxCount(3);

        for ($i = 0; $i < 5; $i++) {
            $this->manager->add("Breadcrumb {$i}");
        }

        $this->assertCount(3, $this->manager->all());
    }
}
