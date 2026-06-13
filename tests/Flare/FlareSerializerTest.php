<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Tests\Flare;

use ErrorWatch\Sdk\Flare\FlareSerializer;
use PHPUnit\Framework\TestCase;

class FlareSerializerTest extends TestCase
{
    private function sampleEnvelope(): array
    {
        return [
            'event_id'    => '11111111-2222-4333-8444-555555555555',
            'timestamp'   => '2026-06-13T10:00:00+00:00',
            'level'       => 'error',
            'message'     => 'Payment failed',
            'exception'   => [
                'type'      => 'App\\Exceptions\\PaymentFailedException',
                'value'     => 'Payment failed',
                'mechanism' => ['handled' => false],
            ],
            'environment' => 'production',
            'release'     => 'v2.3.1',
            'server_name' => 'web-01',
            'status_code' => 500,
            'request'     => ['url' => 'https://shop.example/checkout', 'method' => 'POST'],
            'user'        => ['id' => '918', 'email' => 'client@example.com'],
            'tags'        => ['feature' => 'checkout'],
            'frames'      => [
                [
                    'filename'     => '/app/Services/Payment.php',
                    'lineno'       => 88,
                    'colno'        => 12,
                    'function'     => 'charge',
                    'in_app'       => true,
                    'context_line' => '    throw new PaymentFailedException();',
                    'pre_context'  => ['    $gateway->connect();'],
                    'post_context' => ['  }'],
                ],
            ],
        ];
    }

    public function testMapsCoreFields(): void
    {
        $flare = FlareSerializer::fromEnvelope($this->sampleEnvelope());

        $this->assertSame('App\\Exceptions\\PaymentFailedException', $flare['exceptionClass']);
        $this->assertSame('Payment failed', $flare['message']);
        $this->assertSame('11111111-2222-4333-8444-555555555555', $flare['trackingUuid']);
        $this->assertFalse($flare['handled']);
        $this->assertIsInt($flare['seenAtUnixNano']);
        $this->assertGreaterThan(0, $flare['seenAtUnixNano']);
    }

    public function testFlattensAttributesWithOtelKeys(): void
    {
        $attrs = FlareSerializer::fromEnvelope($this->sampleEnvelope())['attributes'];

        $this->assertSame('production', $attrs['environment']);
        $this->assertSame('v2.3.1', $attrs['release']);
        $this->assertSame('web-01', $attrs['server.name']);
        $this->assertSame('https://shop.example/checkout', $attrs['http.url']);
        $this->assertSame('POST', $attrs['http.method']);
        $this->assertSame(500, $attrs['http.status_code']);
        $this->assertSame('918', $attrs['user.id']);
        $this->assertSame('client@example.com', $attrs['user.email']);
        $this->assertSame('checkout', $attrs['feature']);
    }

    public function testBuildsFlareStacktraceFrame(): void
    {
        $frame = FlareSerializer::fromEnvelope($this->sampleEnvelope())['stacktrace'][0];

        $this->assertSame('/app/Services/Payment.php', $frame['file']);
        $this->assertSame(88, $frame['lineNumber']);
        $this->assertSame(12, $frame['columnNumber']);
        $this->assertSame('charge', $frame['method']);
        $this->assertTrue($frame['isApplicationFrame']);
        // codeSnippet keyed by absolute line number, reconstructed from pre/context/post.
        $this->assertSame('    $gateway->connect();', $frame['codeSnippet']['87']);
        $this->assertSame('    throw new PaymentFailedException();', $frame['codeSnippet']['88']);
        $this->assertSame('  }', $frame['codeSnippet']['89']);
    }

    public function testEnvelopeAndEventsDefaults(): void
    {
        $flare = FlareSerializer::fromEnvelope($this->sampleEnvelope());

        $this->assertSame([], $flare['events']);
        $this->assertNull($flare['code']);
    }
}
