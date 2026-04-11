<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Services;

use ErrorWatch\Laravel\Client\MonitoringClient;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;

class QueueListener
{
    protected MonitoringClient $client;
    protected bool $captureRetries;
    protected array $jobStartTimes = [];

    public function __construct(MonitoringClient $client)
    {
        $this->client = $client;
        $this->captureRetries = $client->getConfig('queue.capture_retries', false);
    }

    /**
     * Register the queue listeners.
     */
    public function register(): void
    {
        Event::listen(JobProcessing::class, [$this, 'onJobProcessing']);
        Event::listen(JobProcessed::class, [$this, 'onJobProcessed']);
        Event::listen(JobFailed::class, [$this, 'onJobFailed']);
        Event::listen(JobExceptionOccurred::class, [$this, 'onJobExceptionOccurred']);
    }

    /**
     * Handle job processing start.
     */
    public function onJobProcessing(JobProcessing $event): void
    {
        if (!$this->client->isEnabled()) {
            return;
        }

        $jobId = $event->job->getJobId() ?? spl_object_id($event->job);

        $this->jobStartTimes[$jobId] = [
            'start_time' => microtime(true),
            'name' => $event->job->resolveName(),
            'queue' => $event->job->getQueue(),
            'attempts' => $event->job->attempts(),
        ];

        // Add breadcrumb
        if ($this->client->getConfig('breadcrumbs.enabled', true)) {
            $this->client->getBreadcrumbManager()->addQueue(
                $event->job->resolveName(),
                $event->job->getQueue(),
                'processing',
                [
                    'job_id' => $jobId,
                    'attempts' => $event->job->attempts(),
                ]
            );
        }
    }

    /**
     * Handle job processed successfully.
     */
    public function onJobProcessed(JobProcessed $event): void
    {
        if (!$this->client->isEnabled()) {
            return;
        }

        $jobId = $event->job->getJobId() ?? spl_object_id($event->job);
        $jobInfo = $this->jobStartTimes[$jobId] ?? null;

        if ($jobInfo) {
            $durationMs = (microtime(true) - $jobInfo['start_time']) * 1000;

            // Add breadcrumb for completion
            if ($this->client->getConfig('breadcrumbs.enabled', true)) {
                $this->client->getBreadcrumbManager()->addQueue(
                    $event->job->resolveName(),
                    $event->job->getQueue(),
                    'completed',
                    [
                        'job_id' => $jobId,
                        'duration_ms' => $durationMs,
                        'attempts' => $event->job->attempts(),
                    ]
                );
            }
        }

        unset($this->jobStartTimes[$jobId]);
    }

    /**
     * Handle job failure.
     */
    public function onJobFailed(JobFailed $event): void
    {
        if (!$this->client->isEnabled()) {
            return;
        }

        $jobId = $event->job->getJobId() ?? spl_object_id($event->job);
        $jobInfo = $this->jobStartTimes[$jobId] ?? [];

        // Capture the exception
        $this->client->captureException($event->exception, [
            'extra' => [
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'job_id' => $jobId,
                'attempts' => $event->job->attempts(),
                'connection' => $event->connectionName,
                'duration_ms' => isset($jobInfo['start_time'])
                    ? (microtime(true) - $jobInfo['start_time']) * 1000
                    : null,
            ],
            'tags' => [
                'job_name' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
            ],
        ]);

        // Add breadcrumb for failure
        if ($this->client->getConfig('breadcrumbs.enabled', true)) {
            $this->client->getBreadcrumbManager()->addQueue(
                $event->job->resolveName(),
                $event->job->getQueue(),
                'failed',
                [
                    'job_id' => $jobId,
                    'attempts' => $event->job->attempts(),
                    'error' => $event->exception->getMessage(),
                ]
            );
        }

        unset($this->jobStartTimes[$jobId]);
    }

    /**
     * Handle job exception (may be retried).
     */
    public function onJobExceptionOccurred(JobExceptionOccurred $event): void
    {
        if (!$this->client->isEnabled()) {
            return;
        }

        // Only capture if configured to capture retries
        if (!$this->captureRetries) {
            return;
        }

        $jobId = $event->job->getJobId() ?? spl_object_id($event->job);
        $jobInfo = $this->jobStartTimes[$jobId] ?? [];

        // Check if job will be retried
        $willRetry = $event->job->attempts() < $event->job->maxTries();

        $this->client->captureMessage(
            "Job exception (will retry: " . ($willRetry ? 'yes' : 'no') . ")",
            'warning',
            [
                'extra' => [
                    'job' => $event->job->resolveName(),
                    'queue' => $event->job->getQueue(),
                    'job_id' => $jobId,
                    'attempts' => $event->job->attempts(),
                    'max_tries' => $event->job->maxTries(),
                    'will_retry' => $willRetry,
                    'exception' => $event->exception->getMessage(),
                    'duration_ms' => isset($jobInfo['start_time'])
                        ? (microtime(true) - $jobInfo['start_time']) * 1000
                        : null,
                ],
            ]
        );

        // Add breadcrumb
        if ($this->client->getConfig('breadcrumbs.enabled', true)) {
            $this->client->getBreadcrumbManager()->addQueue(
                $event->job->resolveName(),
                $event->job->getQueue(),
                'exception',
                [
                    'job_id' => $jobId,
                    'attempts' => $event->job->attempts(),
                    'will_retry' => $willRetry,
                    'error' => $event->exception->getMessage(),
                ]
            );
        }
    }
}
