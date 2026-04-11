<?php

namespace ErrorWatch\Symfony\Tests\Unit\Http;

use ErrorWatch\Symfony\Http\TraceableHttpClient;
use ErrorWatch\Symfony\Http\TraceableResponse;
use ErrorWatch\Symfony\Service\BreadcrumbService;
use ErrorWatch\Symfony\Service\TransactionCollector;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class TraceableHttpClientTest extends TestCase
{
    private HttpClientInterface $innerClient;
    private TransactionCollector $collector;
    private BreadcrumbService $breadcrumbService;

    protected function setUp(): void
    {
        $this->innerClient = $this->createMock(HttpClientInterface::class);
        $this->collector = new TransactionCollector();
        $this->breadcrumbService = new BreadcrumbService();

        $this->collector->startTransaction('test', 'http.server');
    }

    public function testRequestReturnsTraceableResponse(): void
    {
        $innerResponse = $this->createMock(ResponseInterface::class);
        $this->innerClient->expects($this->once())
            ->method('request')
            ->with('GET', 'https://api.example.com/data', [])
            ->willReturn($innerResponse);

        $client = new TraceableHttpClient($this->innerClient, $this->collector, $this->breadcrumbService);

        $response = $client->request('GET', 'https://api.example.com/data');

        $this->assertInstanceOf(TraceableResponse::class, $response);
    }

    public function testResponseAddsSpanOnGetStatusCode(): void
    {
        $innerResponse = $this->createMock(ResponseInterface::class);
        $innerResponse->method('getStatusCode')->willReturn(200);
        $this->innerClient->method('request')->willReturn($innerResponse);

        $client = new TraceableHttpClient($this->innerClient, $this->collector, $this->breadcrumbService);
        $response = $client->request('GET', 'https://api.example.com/data');

        $statusCode = $response->getStatusCode();

        $this->assertSame(200, $statusCode);
        $txn = $this->collector->getCurrentTransaction();
        $this->assertNotNull($txn);
        $spans = $txn->toArray()['spans'];
        $this->assertCount(1, $spans);
        $this->assertSame('http.client', $spans[0]['op']);
    }

    public function testResponseAddsBreadcrumbFor5xx(): void
    {
        $innerResponse = $this->createMock(ResponseInterface::class);
        $innerResponse->method('getStatusCode')->willReturn(503);
        $this->innerClient->method('request')->willReturn($innerResponse);

        $client = new TraceableHttpClient($this->innerClient, $this->collector, $this->breadcrumbService);
        $response = $client->request('GET', 'https://api.example.com/fail');

        $response->getStatusCode();

        $this->assertSame(1, $this->breadcrumbService->count());
        $breadcrumbs = $this->breadcrumbService->all();
        $this->assertSame('http', $breadcrumbs[0]['category']);
        $this->assertStringContainsString('503', $breadcrumbs[0]['message']);
    }

    public function testResponseNoBreadcrumbForSuccessfulRequest(): void
    {
        $innerResponse = $this->createMock(ResponseInterface::class);
        $innerResponse->method('getStatusCode')->willReturn(200);
        $this->innerClient->method('request')->willReturn($innerResponse);

        $client = new TraceableHttpClient($this->innerClient, $this->collector, $this->breadcrumbService);
        $response = $client->request('GET', 'https://api.example.com/ok');

        $response->getStatusCode();

        $this->assertTrue($this->breadcrumbService->isEmpty());
    }

    public function testResponseNoBreadcrumbWhenDisabled(): void
    {
        $innerResponse = $this->createMock(ResponseInterface::class);
        $innerResponse->method('getStatusCode')->willReturn(500);
        $this->innerClient->method('request')->willReturn($innerResponse);

        $client = new TraceableHttpClient(
            $this->innerClient,
            $this->collector,
            $this->breadcrumbService,
            captureErrorsAsBreadcrumbs: false,
        );
        $response = $client->request('GET', 'https://api.example.com/fail');

        $response->getStatusCode();

        $this->assertTrue($this->breadcrumbService->isEmpty());
    }

    public function testSpanFinishedOnlyOnce(): void
    {
        $innerResponse = $this->createMock(ResponseInterface::class);
        $innerResponse->method('getStatusCode')->willReturn(200);
        $innerResponse->method('getContent')->willReturn('{"ok":true}');
        $this->innerClient->method('request')->willReturn($innerResponse);

        $client = new TraceableHttpClient($this->innerClient, $this->collector, $this->breadcrumbService);
        $response = $client->request('GET', 'https://api.example.com/data');

        $response->getStatusCode();
        $response->getContent();

        $txn = $this->collector->getCurrentTransaction();
        $spans = $txn->toArray()['spans'];
        $this->assertCount(1, $spans);
    }
}
