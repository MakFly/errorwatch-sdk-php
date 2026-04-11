<?php

namespace ErrorWatch\Symfony\Http;

use ErrorWatch\Symfony\Model\Span;
use ErrorWatch\Symfony\Service\BreadcrumbService;
use ErrorWatch\Symfony\Service\TransactionCollector;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class TraceableHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $decorated,
        private readonly TransactionCollector $collector,
        private readonly ?BreadcrumbService $breadcrumbService = null,
        private readonly bool $captureErrorsAsBreadcrumbs = true,
    ) {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $span = new Span('http.client', sprintf('%s %s', $method, $url));

        $response = $this->decorated->request($method, $url, $options);

        return new TraceableResponse(
            $response,
            $span,
            $this->collector,
            $this->breadcrumbService,
            $this->captureErrorsAsBreadcrumbs,
            $method,
            $url,
        );
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof TraceableResponse) {
            $responses = [$responses->getInnerResponse()];
        }

        return $this->decorated->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;

        return $clone;
    }
}
