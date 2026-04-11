<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Tests\Breadcrumb;

use ErrorWatch\Sdk\Breadcrumb\Breadcrumb;
use ErrorWatch\Sdk\Breadcrumb\BreadcrumbBag;
use PHPUnit\Framework\TestCase;

class BreadcrumbBagTest extends TestCase
{
    public function test_add_and_count(): void
    {
        $bag = new BreadcrumbBag(10);
        $bag->add(Breadcrumb::log('one'));
        $bag->add(Breadcrumb::log('two'));

        $this->assertSame(2, $bag->count());
    }

    public function test_ring_buffer_drops_oldest(): void
    {
        $bag = new BreadcrumbBag(3);

        $bag->add(new Breadcrumb('nav', 'first'));
        $bag->add(new Breadcrumb('nav', 'second'));
        $bag->add(new Breadcrumb('nav', 'third'));
        $bag->add(new Breadcrumb('nav', 'fourth')); // pushes out 'first'

        $this->assertSame(3, $bag->count());
        $items = $bag->all();
        $this->assertSame('second', $items[0]->message);
        $this->assertSame('third', $items[1]->message);
        $this->assertSame('fourth', $items[2]->message);
    }

    public function test_ring_buffer_many_items(): void
    {
        $bag = new BreadcrumbBag(5);

        for ($i = 1; $i <= 10; $i++) {
            $bag->add(new Breadcrumb('log', "msg-{$i}"));
        }

        $this->assertSame(5, $bag->count());
        $items = $bag->all();
        $this->assertSame('msg-6', $items[0]->message);
        $this->assertSame('msg-10', $items[4]->message);
    }

    public function test_to_array(): void
    {
        $bag = new BreadcrumbBag(10);
        $bag->add(Breadcrumb::log('click'));

        $arr = $bag->toArray();

        $this->assertCount(1, $arr);
        $this->assertIsArray($arr[0]);
        $this->assertSame('log', $arr[0]['category']);
        $this->assertSame('click', $arr[0]['message']);
    }

    public function test_clear(): void
    {
        $bag = new BreadcrumbBag(10);
        $bag->add(Breadcrumb::log('one'));
        $bag->add(Breadcrumb::log('two'));
        $bag->clear();

        $this->assertSame(0, $bag->count());
        $this->assertEmpty($bag->all());
    }

    public function test_max_size_zero_stores_nothing(): void
    {
        $bag = new BreadcrumbBag(0);
        $bag->add(Breadcrumb::log('ignored'));

        $this->assertSame(0, $bag->count());
    }
}
