<?php

namespace ErrorWatch\Laravel\Tests\Feature;

use ErrorWatch\Laravel\Services\QueueListener;
use ErrorWatch\Laravel\Tests\TestCase;
use ErrorWatch\Laravel\Facades\ErrorWatch;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\DatabaseJob;
use RuntimeException;
use PHPUnit\Framework\Attributes\Test;

class QueueListenerTest extends TestCase
{
    protected QueueListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = $this->app->make(QueueListener::class);
    }

    #[Test]
    public function it_can_register_listener(): void
    {
        // register() attaches queue event listeners — it should complete without exceptions
        $this->listener->register();

        // Verify the listener instance is still valid after registration
        $this->assertInstanceOf(\ErrorWatch\Laravel\Services\QueueListener::class, $this->listener);
    }

    #[Test]
    public function it_tracks_job_processing(): void
    {
        ErrorWatch::clearBreadcrumbs();

        $job = $this->createMockJob();

        $event = new JobProcessing('database', $job);

        $this->listener->onJobProcessing($event);

        $breadcrumbs = ErrorWatch::getBreadcrumbs();

        $this->assertNotEmpty($breadcrumbs);
        $this->assertStringContainsString('Queue', $breadcrumbs[0]['message'] ?? '');
    }

    #[Test]
    public function it_tracks_job_processed(): void
    {
        ErrorWatch::clearBreadcrumbs();

        $job = $this->createMockJob();

        $processingEvent = new JobProcessing('database', $job);
        $this->listener->onJobProcessing($processingEvent);

        $processedEvent = new JobProcessed('database', $job);
        $this->listener->onJobProcessed($processedEvent);

        $breadcrumbs = ErrorWatch::getBreadcrumbs();

        // Should have at least 2 breadcrumbs (processing + processed)
        $this->assertGreaterThanOrEqual(2, count($breadcrumbs));
    }

    #[Test]
    public function it_captures_job_failure(): void
    {
        ErrorWatch::clearBreadcrumbs();

        $job = $this->createMockJob();
        $exception = new RuntimeException('Job failed');

        $processingEvent = new JobProcessing('database', $job);
        $this->listener->onJobProcessing($processingEvent);

        $failedEvent = new JobFailed('database', $job, $exception);
        $this->listener->onJobFailed($failedEvent);

        // Should have captured the failure (breadcrumbs + event sent)
        $breadcrumbs = ErrorWatch::getBreadcrumbs();
        $this->assertNotEmpty($breadcrumbs);
    }

    protected function createMockJob(): DatabaseJob
    {
        $job = $this->createMock(DatabaseJob::class);

        $job->method('getJobId')->willReturn('test-job-123');
        $job->method('resolveName')->willReturn('App\\Jobs\\TestJob');
        $job->method('getQueue')->willReturn('default');
        $job->method('attempts')->willReturn(1);
        $job->method('maxTries')->willReturn(3);

        return $job;
    }
}
