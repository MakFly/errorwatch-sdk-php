<?php

namespace ErrorWatch\Symfony\Service;

use ErrorWatch\Sdk\Exception\StacktraceBuilder;
use ErrorWatch\Sdk\Tracing\TraceContext;
use ErrorWatch\Symfony\Http\MonitoringClientInterface;

class ErrorSender implements ErrorSenderInterface
{
    public function __construct(
        private readonly bool $enabled,
        private readonly ?string $environment,
        private readonly ?string $release,
        private readonly MonitoringClientInterface $client,
        private readonly LevelMapper $levelMapper,
        private readonly ?FingerprintGenerator $fingerprintGenerator = null,
        private readonly ?StacktraceBuilder $stacktraceBuilder = null,
        private readonly ?TraceContext $traceContext = null,
        private readonly bool $framesEnabled = true,
    ) {
    }

    public function send(
        \Throwable $throwable,
        ?string $url = null,
        ?string $level = null,
        ?string $sessionId = null,
        array $context = [],
    ): void {
        if (!$this->enabled) {
            return;
        }

        $payload = $this->buildPayload($throwable, $url, $level, $sessionId, $context);
        $this->client->sendEventAsync($payload);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function buildPayload(
        \Throwable $throwable,
        ?string $url,
        ?string $level,
        ?string $sessionId,
        array $context = [],
    ): array {
        $trace = $throwable->getTraceAsString();
        $file = $throwable->getFile();
        $line = $throwable->getLine();

        $resolvedLevel = $level;
        if (null === $resolvedLevel || !LevelMapper::isValidLevel($resolvedLevel)) {
            $resolvedLevel = $this->levelMapper->mapException($throwable);
        }

        $payload = [
            'message' => $throwable->getMessage(),
            'file' => $file,
            'line' => $line,
            'stack' => $trace,
            'env' => $this->environment,
            'url' => $url,
            'level' => $resolvedLevel,
            'release' => $this->release,
            'created_at' => (int) (microtime(true) * 1000),
        ];

        // --- Sprint 1 enrichments ---

        // Explicit fingerprint keeps grouping stable across deploys and
        // wins over the default file/line fingerprint on the API side.
        if (null !== $this->fingerprintGenerator) {
            $payload['fingerprint'] = $this->fingerprintGenerator->generate(
                $throwable->getMessage(),
                $file,
                $line,
            );
        }

        // Structured frames with in_app detection + source context, so the
        // dashboard can render a proper stack viewer.
        if ($this->framesEnabled && null !== $this->stacktraceBuilder) {
            $payload['frames'] = array_map(
                static fn ($frame) => $frame->toArray(),
                $this->stacktraceBuilder->buildFromThrowable($throwable),
            );
        }

        // Distributed tracing correlation — errors become navigable from
        // the Overview widget and from logs carrying the same trace_id.
        if (null !== $this->traceContext && $this->traceContext->hasContext()) {
            $payload['trace_id'] = $this->traceContext->getTraceId();
            $payload['span_id'] = $this->traceContext->getCurrentSpanId();
        }

        if ($throwable instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            $payload['status_code'] = $throwable->getStatusCode();
        }

        $criticalLevels = [LevelMapper::LEVEL_FATAL, LevelMapper::LEVEL_ERROR];
        if (null !== $sessionId && in_array($resolvedLevel, $criticalLevels, true)) {
            $payload['session_id'] = $sessionId;
        }

        if (!empty($context['breadcrumbs'])) {
            $payload['breadcrumbs'] = $context['breadcrumbs'];
        }
        if (!empty($context['user'])) {
            $payload['user'] = $context['user'];
        }
        if (!empty($context['request'])) {
            $payload['request'] = $context['request'];
        }

        return $payload;
    }
}
