<?php

namespace ErrorWatch\Symfony\Tests\Unit\Service;

use ErrorWatch\Symfony\Model\Breadcrumb;
use ErrorWatch\Symfony\Service\BreadcrumbService;
use PHPUnit\Framework\TestCase;

final class BreadcrumbServiceTest extends TestCase
{
    private BreadcrumbService $service;

    protected function setUp(): void
    {
        $this->service = new BreadcrumbService(maxBreadcrumbs: 5);
    }

    public function testAddBreadcrumb(): void
    {
        $breadcrumb = new Breadcrumb('user', time(), message: 'Test action');

        $this->service->add($breadcrumb);

        $this->assertCount(1, $this->service->all());
        $this->assertSame(1, $this->service->count());
    }

    public function testAddArrayBreadcrumb(): void
    {
        $this->service->addArray([
            'category' => 'http',
            'message' => 'GET /api/test',
        ]);

        $all = $this->service->all();
        $this->assertCount(1, $all);
        $this->assertSame('http', $all[0]['category']);
    }

    public function testMaxBreadcrumbsLimit(): void
    {
        for ($i = 0; $i < 7; ++$i) {
            $this->service->add(new Breadcrumb('user', time(), message: "Action $i"));
        }

        $this->assertCount(5, $this->service->all());

        // Verify oldest breadcrumbs were removed
        $all = $this->service->all();
        $this->assertSame('Action 2', $all[0]['message']);
        $this->assertSame('Action 6', $all[4]['message']);
    }

    public function testClear(): void
    {
        $this->service->add(new Breadcrumb('user', time()));
        $this->service->add(new Breadcrumb('http', time()));

        $this->assertCount(2, $this->service->all());

        $this->service->clear();

        $this->assertEmpty($this->service->all());
        $this->assertSame(0, $this->service->count());
    }

    public function testIsEmpty(): void
    {
        $this->assertTrue($this->service->isEmpty());

        $this->service->add(new Breadcrumb('user', time()));

        $this->assertFalse($this->service->isEmpty());
    }

    public function testAllReturnsArrayRepresentation(): void
    {
        $breadcrumb = new Breadcrumb('http', time(), message: 'GET /test');
        $this->service->add($breadcrumb);

        $all = $this->service->all();

        $this->assertIsArray($all);
        $this->assertArrayHasKey(0, $all);
        $this->assertArrayHasKey('category', $all[0]);
        $this->assertSame('http', $all[0]['category']);
    }

    public function testDefaultMaxBreadcrumbs(): void
    {
        $service = new BreadcrumbService();

        // Add more than default (100)
        for ($i = 0; $i < 150; ++$i) {
            $service->add(new Breadcrumb('user', time()));
        }

        $this->assertCount(100, $service->all());
    }
}
