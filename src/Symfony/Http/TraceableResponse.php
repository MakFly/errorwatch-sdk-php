<?php

namespace ErrorWatch\Symfony\Http;

use ErrorWatch\Symfony\Model\Breadcrumb;
use ErrorWatch\Symfony\Model\Span;
use ErrorWatch\Symfony\Service\BreadcrumbService;
use ErrorWatch\Symfony\Service\TransactionCollector;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class TraceableResponse implements ResponseInterface
{
    private bool $spanFinished = false;

    public function __construct(
        private readonly ResponseInterface $response,
        private readonly Span $span,
        private readonly TransactionCollector $collector,
        private readonly ?BreadcrumbService $breadcrumbService,
        private readonly bool $captureErrorsAsBreadcrumbs,
        private readonly string $method,
        private readonly string $url,
    ) {
    }

    public function getStatusCode(): int
    {
        $statusCode = $this->response->getStatusCode();
        $this->finishSpan($statusCode);

        return $statusCode;
    }

    public function getHeaders(bool $throw = true): array
    {
        return $this->response->getHeaders($throw);
    }

    public function getContent(bool $throw = true): string
    {
        $content = $this->response->getContent($throw);
        $this->finishSpanFromResponse();

        return $content;
    }

    public function toArray(bool $throw = true): array
    {
        $array = $this->response->toArray($throw);
        $this->finishSpanFromResponse();

        return $array;
    }

    public function cancel(): void
    {
        $this->response->cancel();
        $this->finishSpan(0, 'cancelled');
    }

    public function getInfo(?string $type = null): mixed
    {
        return $this->response->getInfo($type);
    }

    public function getInnerResponse(): ResponseInterface
    {
        return $this->response;
    }

    private function finishSpanFromResponse(): void
    {
        if ($this->spanFinished) {
            return;
        }

        try {
            $statusCode = $this->response->getStatusCode();
            $this->finishSpan($statusCode);
        } catch (\Throwable) {
            $this->finishSpan(0, 'error');
        }
    }

    private function finishSpan(int $statusCode, ?string $forceStatus = null): void
    {
        if ($this->spanFinished) {
            return;
        }

        $this->spanFinished = true;

        $status = $forceStatus ?? ($statusCode >= 500 ? 'error' : 'ok');
        $this->span->setStatus($status);
        $this->span->setData('http.status_code', $statusCode);
        $this->span->finish();
        $this->collector->addSpan($this->span);

        if ($this->captureErrorsAsBreadcrumbs && $statusCode >= 500) {
            $this->breadcrumbService?->add(Breadcrumb::http(
                $this->method,
                $this->url,
                $statusCode,
                sprintf('%s %s returned %d', $this->method, $this->url, $statusCode),
            ));
        }
    }
}
