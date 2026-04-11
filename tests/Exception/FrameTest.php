<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Tests\Exception;

use ErrorWatch\Sdk\Exception\Frame;
use PHPUnit\Framework\TestCase;

class FrameTest extends TestCase
{
    public function test_minimal_frame(): void
    {
        $frame = new Frame(filename: '/app/src/Foo.php');
        $arr   = $frame->toArray();

        $this->assertSame('/app/src/Foo.php', $arr['filename']);
        $this->assertTrue($arr['in_app']);
        $this->assertArrayNotHasKey('function', $arr);
        $this->assertArrayNotHasKey('lineno', $arr);
        $this->assertArrayNotHasKey('colno', $arr);
        $this->assertArrayNotHasKey('context_line', $arr);
        $this->assertArrayNotHasKey('pre_context', $arr);
        $this->assertArrayNotHasKey('post_context', $arr);
    }

    public function test_full_frame(): void
    {
        $frame = new Frame(
            filename:    '/app/src/Foo.php',
            function:    'Foo::bar',
            lineno:      42,
            colno:       5,
            inApp:       true,
            contextLine: '    $x = doSomething();',
            preContext:  ['    $a = 1;', '    $b = 2;'],
            postContext: ['    return $x;'],
        );

        $arr = $frame->toArray();

        $this->assertSame('/app/src/Foo.php', $arr['filename']);
        $this->assertSame('Foo::bar', $arr['function']);
        $this->assertSame(42, $arr['lineno']);
        $this->assertSame(5, $arr['colno']);
        $this->assertTrue($arr['in_app']);
        $this->assertSame('    $x = doSomething();', $arr['context_line']);
        $this->assertSame(['    $a = 1;', '    $b = 2;'], $arr['pre_context']);
        $this->assertSame(['    return $x;'], $arr['post_context']);
    }

    public function test_vendor_frame_not_in_app(): void
    {
        $frame = new Frame(
            filename: '/app/vendor/guzzlehttp/guzzle/src/Client.php',
            inApp:    false,
        );

        $this->assertFalse($frame->toArray()['in_app']);
    }
}
