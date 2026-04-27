<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Tests\Event;

use ErrorWatch\Sdk\Event\Event;
use ErrorWatch\Sdk\Event\Severity;
use ErrorWatch\Sdk\Options;
use PHPUnit\Framework\TestCase;

class EventTest extends TestCase
{
    private function makeOptions(): Options
    {
        return new Options([
            'endpoint'     => 'https://api.errorwatch.io',
            'api_key'      => 'ew_live_abc123',
            'environment'  => 'testing',
            'release'      => '2.0.0',
            'server_name'  => 'test-host',
            'project_root' => '/app',
        ]);
    }

    public function test_from_message_payload_structure(): void
    {
        $event   = Event::fromMessage('Hello world', Severity::WARNING);
        $payload = $event->toPayload();

        $this->assertArrayHasKey('event_id', $payload);
        $this->assertArrayHasKey('timestamp', $payload);
        $this->assertSame('php', $payload['platform']);
        $this->assertSame('warning', $payload['level']);
        $this->assertSame('Hello world', $payload['message']);
        $this->assertArrayHasKey('sdk', $payload);
        $this->assertSame('errorwatch-php', $payload['sdk']['name']);
        $this->assertSame('2.1.0', $payload['sdk']['version']);
        $this->assertArrayHasKey('contexts', $payload);
        $this->assertArrayHasKey('runtime', $payload['contexts']);
        $this->assertArrayHasKey('os', $payload['contexts']);
    }

    public function test_from_exception_payload_structure(): void
    {
        $e       = new \RuntimeException('Something exploded');
        $event   = Event::fromException($e, $this->makeOptions());
        $payload = $event->toPayload();

        $this->assertSame('php', $payload['platform']);
        $this->assertSame('error', $payload['level']);
        $this->assertArrayHasKey('exception', $payload);
        $this->assertSame('RuntimeException', $payload['exception']['type']);
        $this->assertSame('Something exploded', $payload['exception']['value']);
        $this->assertArrayHasKey('frames', $payload);
        $this->assertIsArray($payload['frames']);
        $this->assertSame('testing', $payload['environment']);
        $this->assertSame('2.0.0', $payload['release']);
        $this->assertSame('test-host', $payload['server_name']);
    }

    public function test_event_id_is_uuid_v4(): void
    {
        $event   = Event::fromMessage('test', Severity::INFO);
        $payload = $event->toPayload();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $payload['event_id'],
        );
    }

    public function test_timestamp_is_iso8601(): void
    {
        $event   = Event::fromMessage('test', Severity::INFO);
        $payload = $event->toPayload();

        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $payload['timestamp']));
    }

    public function test_set_tags_merged(): void
    {
        $event = Event::fromMessage('test', Severity::INFO);
        $event->setTags(['a' => '1']);
        $event->setTags(['b' => '2']);

        $payload = $event->toPayload();
        $this->assertSame(['a' => '1', 'b' => '2'], $payload['tags']);
    }

    public function test_set_extras_merged(): void
    {
        $event = Event::fromMessage('test', Severity::INFO);
        $event->setExtras(['x' => '1']);
        $event->setExtras(['y' => '2']);

        $payload = $event->toPayload();
        $this->assertSame(['x' => '1', 'y' => '2'], $payload['extra']);
    }

    public function test_user_request_breadcrumbs_in_payload(): void
    {
        $event = Event::fromMessage('test', Severity::INFO);
        $event->setUser(['id' => '1', 'email' => 'a@b.com']);
        $event->setRequest(['url' => '/foo', 'method' => 'GET', 'headers' => []]);
        $event->setBreadcrumbs([['timestamp' => '2026-01-01T00:00:00+00:00', 'category' => 'log', 'level' => 'info']]);
        $event->setFingerprint(['MyError', 'myfile.php']);

        $payload = $event->toPayload();

        $this->assertSame(['id' => '1', 'email' => 'a@b.com'], $payload['user']);
        $this->assertSame('/foo', $payload['request']['url']);
        $this->assertCount(1, $payload['breadcrumbs']);
        $this->assertSame(['MyError', 'myfile.php'], $payload['fingerprint']);
    }

    public function test_empty_arrays_are_omitted(): void
    {
        $event   = Event::fromMessage('test', Severity::INFO);
        $payload = $event->toPayload();

        $this->assertArrayNotHasKey('tags', $payload);
        $this->assertArrayNotHasKey('extra', $payload);
        $this->assertArrayNotHasKey('user', $payload);
        $this->assertArrayNotHasKey('request', $payload);
        $this->assertArrayNotHasKey('breadcrumbs', $payload);
        $this->assertArrayNotHasKey('fingerprint', $payload);
    }

    public function test_contexts_contain_runtime_and_os(): void
    {
        $event   = Event::fromMessage('test', Severity::INFO);
        $payload = $event->toPayload();

        $this->assertArrayHasKey('runtime', $payload['contexts']);
        $this->assertSame('php', $payload['contexts']['runtime']['name']);
        $this->assertSame(PHP_VERSION, $payload['contexts']['runtime']['version']);
        $this->assertArrayHasKey('os', $payload['contexts']);
    }
}
