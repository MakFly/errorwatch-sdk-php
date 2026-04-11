<?php

namespace ErrorWatch\Laravel\Tests\Unit;

use ErrorWatch\Laravel\Services\ExceptionCapturer;
use ErrorWatch\Laravel\Tests\TestCase;
use RuntimeException;
use PHPUnit\Framework\Attributes\Test;

class ExceptionCapturerTest extends TestCase
{
    #[Test]
    public function it_can_format_exception(): void
    {
        $exception = new RuntimeException('Test exception', 500);

        $formatted = ExceptionCapturer::format($exception);

        $this->assertEquals(RuntimeException::class, $formatted['type']);
        $this->assertEquals('Test exception', $formatted['message']);
        $this->assertEquals(500, $formatted['code']);
        $this->assertArrayHasKey('file', $formatted);
        $this->assertArrayHasKey('line', $formatted);
        $this->assertArrayHasKey('stacktrace', $formatted);
    }

    #[Test]
    public function it_can_format_stack_trace(): void
    {
        $exception = new RuntimeException('Test');

        $trace = ExceptionCapturer::formatStackTrace($exception->getTrace());

        $this->assertIsArray($trace);
    }

    #[Test]
    public function it_can_limit_stack_trace_frames(): void
    {
        // Create nested exception for longer trace
        $exception = null;
        try {
            throw new RuntimeException('Level 3');
        } catch (RuntimeException $e3) {
            try {
                throw new RuntimeException('Level 2', 0, $e3);
            } catch (RuntimeException $e2) {
                try {
                    throw new RuntimeException('Level 1', 0, $e2);
                } catch (RuntimeException $e1) {
                    $exception = $e1;
                }
            }
        }

        $trace = ExceptionCapturer::formatStackTrace($exception->getTrace(), 2);

        $this->assertLessThanOrEqual(2, count($trace));
    }

    #[Test]
    public function it_can_format_previous_exception(): void
    {
        $previous = new RuntimeException('Previous exception');
        $exception = new RuntimeException('Main exception', 0, $previous);

        $formatted = ExceptionCapturer::format($exception);

        $this->assertNotNull($formatted['previous']);
        $this->assertEquals('Previous exception', $formatted['previous']['message']);
    }

    #[Test]
    public function it_can_extract_context(): void
    {
        $exception = new RuntimeException('Test');

        $context = ExceptionCapturer::extractContext($exception);

        $this->assertIsArray($context);
    }
}
