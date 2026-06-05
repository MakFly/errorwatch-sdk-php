<?php

namespace ErrorWatch\Laravel\Tests\Unit;

use ErrorWatch\Laravel\Logging\ErrorWatchLogger;
use ErrorWatch\Laravel\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ErrorWatchLoggerTest extends TestCase
{
    private function makeLogger(): ErrorWatchLogger
    {
        return new ErrorWatchLogger($this->client, config('errorwatch'));
    }

    #[Test]
    public function it_truncates_a_message_above_the_backend_cap(): void
    {
        $logger = $this->makeLogger();
        $long   = str_repeat('A', 25_000);

        $ref      = new \ReflectionMethod($logger, 'truncateMessage');
        $clipped  = $ref->invoke($logger, $long);

        // The backend rejects message > 20000; we clip well under it (18000)
        // plus a short marker, which is still far below the hard cap.
        $this->assertLessThanOrEqual(20_000, mb_strlen($clipped));
        $this->assertStringContainsString('…[truncated', $clipped);
        $this->assertStringContainsString('7000 chars]', $clipped); // 25000 - 18000
    }

    #[Test]
    public function it_leaves_a_short_message_untouched(): void
    {
        $logger  = $this->makeLogger();
        $message = 'A short error message';

        $ref     = new \ReflectionMethod($logger, 'truncateMessage');

        $this->assertSame($message, $ref->invoke($logger, $message));
    }

    #[Test]
    public function send_live_log_attaches_the_scope_status_code(): void
    {
        // Simulate the post-response snapshot the middleware records.
        $this->client->setRequestContext(
            ['url' => 'https://app.test/orders', 'method' => 'GET'],
            422,
        );

        // Spy on the transport so we can capture the outgoing log payload
        // without hitting the network.
        $captured = null;
        $spy = new class($captured) extends \ErrorWatch\Laravel\Transport\HttpTransport {
            public ?array $captured = null;
            public function __construct(&$out)
            {
                parent::__construct('https://test.errorwatch.io', 'k');
            }
            public function sendLog(array $logEntry): bool
            {
                $this->captured = $logEntry;
                return true;
            }
        };

        // Swap the transport on the client via reflection.
        $ref  = new \ReflectionProperty($this->client, 'transport');
        $orig = $ref->getValue($this->client);
        $ref->setValue($this->client, $spy);

        try {
            $logger = $this->makeLogger();
            $sendLiveLog = new \ReflectionMethod($logger, 'sendLiveLog');
            $sendLiveLog->invoke($logger, 'error', 'boom', []);
        } finally {
            $ref->setValue($this->client, $orig);
        }

        $this->assertNotNull($spy->captured);
        $this->assertSame('https://app.test/orders', $spy->captured['url']);
        // Top-level status_code feeds the backend application_logs.status_code
        // column / log ingest field so logs are filterable by HTTP status.
        $this->assertSame(422, $spy->captured['status_code']);
        // Kept in context too, for backends without the top-level field.
        $context = (array) $spy->captured['context'];
        $this->assertSame(422, $context['status_code']);
    }

    #[Test]
    public function send_live_log_parses_http_status_from_legacy_message_format(): void
    {
        $captured = null;
        $spy = new class($captured) extends \ErrorWatch\Laravel\Transport\HttpTransport {
            public ?array $captured = null;
            public function __construct(&$out)
            {
                parent::__construct('https://test.errorwatch.io', 'k');
            }
            public function sendLog(array $logEntry): bool
            {
                $this->captured = $logEntry;
                return true;
            }
        };

        $ref  = new \ReflectionProperty($this->client, 'transport');
        $orig = $ref->getValue($this->client);
        $ref->setValue($this->client, $spy);

        try {
            $logger = $this->makeLogger();
            $sendLiveLog = new \ReflectionMethod($logger, 'sendLiveLog');
            $message = "[RESPONSE][UNPROCESSABLE ENTITY] Status Code : 422 \n";
            $sendLiveLog->invoke($logger, 'warning', $message, ['channel' => 'iapi']);
        } finally {
            $ref->setValue($this->client, $orig);
        }

        $this->assertNotNull($spy->captured);
        $this->assertSame(422, $spy->captured['status_code']);
        $this->assertSame('http', $spy->captured['source']);
    }

    #[Test]
    public function send_live_log_prefers_explicit_monolog_context_over_message_parse(): void
    {
        $captured = null;
        $spy = new class($captured) extends \ErrorWatch\Laravel\Transport\HttpTransport {
            public ?array $captured = null;
            public function __construct(&$out)
            {
                parent::__construct('https://test.errorwatch.io', 'k');
            }
            public function sendLog(array $logEntry): bool
            {
                $this->captured = $logEntry;
                return true;
            }
        };

        $ref  = new \ReflectionProperty($this->client, 'transport');
        $orig = $ref->getValue($this->client);
        $ref->setValue($this->client, $spy);

        try {
            $logger = $this->makeLogger();
            $sendLiveLog = new \ReflectionMethod($logger, 'sendLiveLog');
            $sendLiveLog->invoke($logger, 'warning', 'Status Code : 500', [
                'channel' => 'iapi',
                'context' => ['status_code' => 201, 'log_kind' => 'http_response'],
            ]);
        } finally {
            $ref->setValue($this->client, $orig);
        }

        $this->assertSame(201, $spy->captured['status_code']);
        $this->assertSame('http', $spy->captured['source']);
    }
}
