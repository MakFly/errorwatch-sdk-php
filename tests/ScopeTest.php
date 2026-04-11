<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Tests;

use ErrorWatch\Sdk\Breadcrumb\Breadcrumb;
use ErrorWatch\Sdk\Event\Event;
use ErrorWatch\Sdk\Event\Severity;
use ErrorWatch\Sdk\Options;
use ErrorWatch\Sdk\Scope;
use PHPUnit\Framework\TestCase;

class ScopeTest extends TestCase
{
    private function makeOptions(): Options
    {
        return new Options([
            'endpoint' => 'https://api.errorwatch.io',
            'api_key'  => 'ew_live_abc123',
        ]);
    }

    public function test_set_and_get_user(): void
    {
        $scope = new Scope();
        $scope->setUser(['id' => '42', 'email' => 'user@example.com']);

        $this->assertSame(['id' => '42', 'email' => 'user@example.com'], $scope->getUser());
    }

    public function test_set_tag_and_tags(): void
    {
        $scope = new Scope();
        $scope->setTags(['env' => 'prod']);
        $scope->setTag('version', '2.0.0');

        $this->assertSame(['env' => 'prod', 'version' => '2.0.0'], $scope->getTags());
    }

    public function test_set_extra_and_extras(): void
    {
        $scope = new Scope();
        $scope->setExtras(['memory' => '256MB']);
        $scope->setExtra('cpu', '4');

        $this->assertSame(['memory' => '256MB', 'cpu' => '4'], $scope->getExtras());
    }

    public function test_add_breadcrumb(): void
    {
        $scope = new Scope();
        $bc    = Breadcrumb::log('Test log message');
        $scope->addBreadcrumb($bc);

        $this->assertCount(1, $scope->getBreadcrumbs());
    }

    public function test_set_fingerprint(): void
    {
        $scope = new Scope();
        $scope->setFingerprint(['MyException', 'database']);

        $this->assertSame(['MyException', 'database'], $scope->getFingerprint());
    }

    public function test_set_level(): void
    {
        $scope = new Scope();
        $scope->setLevel(Severity::WARNING);

        $this->assertSame(Severity::WARNING, $scope->getLevel());
    }

    public function test_clear_resets_all_fields(): void
    {
        $scope = new Scope();
        $scope->setUser(['id' => '1']);
        $scope->setTag('k', 'v');
        $scope->setExtra('x', 'y');
        $scope->addBreadcrumb(Breadcrumb::log('msg'));
        $scope->setFingerprint(['fp']);
        $scope->setLevel(Severity::FATAL);

        $scope->clear();

        $this->assertNull($scope->getUser());
        $this->assertEmpty($scope->getTags());
        $this->assertEmpty($scope->getExtras());
        $this->assertEmpty($scope->getBreadcrumbs());
        $this->assertNull($scope->getFingerprint());
        $this->assertNull($scope->getLevel());
    }

    public function test_apply_to_event_merges_context(): void
    {
        $scope = new Scope();
        $scope->setUser(['id' => '99']);
        $scope->setTag('host', 'web-01');
        $scope->setExtra('session_id', 'abc');
        $scope->addBreadcrumb(Breadcrumb::log('click'));
        $scope->setFingerprint(['CustomFP']);
        $scope->setLevel(Severity::WARNING);

        $event = Event::fromMessage('hello', Severity::INFO);
        $scope->applyToEvent($event);

        $payload = $event->toPayload();

        $this->assertSame(['id' => '99'], $payload['user']);
        $this->assertSame(['host' => 'web-01'], $payload['tags']);
        $this->assertSame(['session_id' => 'abc'], $payload['extra']);
        $this->assertCount(1, $payload['breadcrumbs']);
        $this->assertSame(['CustomFP'], $payload['fingerprint']);
        $this->assertSame('warning', $payload['level']); // level overridden by scope
    }

    public function test_apply_to_event_without_data_leaves_event_unchanged(): void
    {
        $scope = new Scope();
        $event = Event::fromMessage('baseline', Severity::INFO);
        $scope->applyToEvent($event);

        $payload = $event->toPayload();

        $this->assertArrayNotHasKey('user', $payload);
        $this->assertArrayNotHasKey('tags', $payload);
        $this->assertArrayNotHasKey('extra', $payload);
        $this->assertArrayNotHasKey('breadcrumbs', $payload);
    }
}
