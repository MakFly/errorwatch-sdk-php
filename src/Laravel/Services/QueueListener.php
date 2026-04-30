<?php

declare(strict_types=1);

namespace ErrorWatch\Laravel\Services;

use ErrorWatch\Laravel\Client\MonitoringClient;
use ErrorWatch\Laravel\Profiler\RequestProfile;
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

            $this->pushToProfile($jobInfo['queue'] ?? '', $jobInfo['name'] ?? '', 'processed', $durationMs);
        }

        unset($this->jobStartTimes[$jobId]);
    }

    private function pushToProfile(string $queue, string $class, string $status, float $durationMs): void
    {
        if (!$this->client->getConfig('profiler.enabled', false)) {
            return;
        }
        try {
            $profile = app(RequestProfile::class);
            if ($profile->isStarted()) {
                $profile->recordJob($queue, $class, $status, $durationMs);
            }
        } catch (\Throwable) {
        }
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

        $duration = isset($jobInfo['start_time']) ? (microtime(true) - $jobInfo['start_time']) * 1000 : 0.0;
        $this->pushToProfile($jobInfo['queue'] ?? $event->job->getQueue(), $jobInfo['name'] ?? $event->job->resolveName(), 'failed', $duration);

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
