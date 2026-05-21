<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Tests\Unit;

use ErrorWatch\Laravel\Client\MonitoringClient;
use ErrorWatch\Laravel\Jobs\SendEventJob;
use ErrorWatch\Laravel\Tests\TestCase;
use ErrorWatch\Laravel\Transport\HttpTransport;
use ErrorWatch\Sdk\Transport\RequestBudget;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * Queue workers reuse the MonitoringClient singleton, so the transport's
 * RequestBudget is shared across every job. SendEventJob::handle() must reset
 * that budget so a job is never starved by the I/O time of previous jobs.
 */
final class SendEventJobTest extends TestCase
{
    #[Test]
    public function handle_resets_the_shared_request_budget_before_sending(): void
    {
        // Simulate a budget already exhausted by previous jobs on the same worker.
        $budget = new RequestBudget(50);
        $budget->consume(999.0);
        $this->assertFalse($budget->withinBudget(), 'precondition: budget exhausted');

        $transport = Mockery::mock(HttpTransport::class);
        $transport->shouldReceive('getBudget')->andReturn($budget);
        $transport->shouldReceive('send')->once()->andReturn(true);

        $client = Mockery::mock(MonitoringClient::class);
        $client->shouldReceive('getTransport')->andReturn($transport);

        (new SendEventJob('event', ['event_id' => 'evt-1']))->handle($client);

        $this->assertTrue(
            $budget->withinBudget(),
            'SendEventJob::handle() must reset the shared budget so later jobs are not dropped',
        );
    }

    #[Test]
    public function handle_routes_each_kind_to_the_matching_transport_method(): void
    {
        $budget = new RequestBudget(50);

        $transport = Mockery::mock(HttpTransport::class);
        $transport->shouldReceive('getBudget')->andReturn($budget);
        $transport->shouldReceive('sendTransaction')->once()->with(['t' => 1], 'production')->andReturn(true);

        $client = Mockery::mock(MonitoringClient::class);
        $client->shouldReceive('getTransport')->andReturn($transport);

        (new SendEventJob('transaction', ['t' => 1], 'production'))->handle($client);

        $this->addToAssertionCount(1);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
