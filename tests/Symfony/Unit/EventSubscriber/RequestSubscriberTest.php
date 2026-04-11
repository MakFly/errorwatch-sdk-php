<?php

namespace ErrorWatch\Symfony\Tests\Unit\EventSubscriber;

use ErrorWatch\Symfony\EventSubscriber\RequestSubscriber;
use ErrorWatch\Symfony\Http\MonitoringClientInterface;
use ErrorWatch\Symfony\Service\TransactionCollector;
use ErrorWatch\Symfony\Service\TransactionSender;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RequestSubscriberTest extends TestCase
{
    private TransactionCollector $collector;
    private MonitoringClientInterface $mockClient;
    private TransactionSender $sender;
    private HttpKernelInterface $kernel;

    protected function setUp(): void
    {
        $this->collector = new TransactionCollector();
        $this->mockClient = $this->createMock(MonitoringClientInterface::class);
        $this->sender = new TransactionSender(
            client: $this->mockClient,
            enabled: true,
            environment: 'test',
        );
        $this->kernel = $this->createMock(HttpKernelInterface::class);
    }

    public function testOnRequestCreatesTransaction(): void
    {
        $subscriber = new RequestSubscriber($this->collector, $this->sender, true);

        $request = Request::create('/api/users', 'GET');
        $request->attributes->set('_route', 'api_users_list');
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onRequest($event);

        $this->assertTrue($this->collector->hasTransaction());
        $txn = $this->collector->getCurrentTransaction();
        $this->assertSame('GET api_users_list', $txn->toArray()['name']);
    }

    public function testOnRequestUsesPathInfoWhenNoRoute(): void
    {
        $subscriber = new RequestSubscriber($this->collector, $this->sender, true);

        $request = Request::create('/api/users', 'POST');
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onRequest($event);

        $txn = $this->collector->getCurrentTransaction();
        $this->assertSame('POST /api/users', $txn->toArray()['name']);
    }

    public function testOnRequestIgnoresSubRequests(): void
    {
        $subscriber = new RequestSubscriber($this->collector, $this->sender, true);

        $request = Request::create('/api/users', 'GET');
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::SUB_REQUEST);

        $subscriber->onRequest($event);

        $this->assertFalse($this->collector->hasTransaction());
    }

    public function testOnRequestIgnoresExcludedRoutes(): void
    {
        $subscriber = new RequestSubscriber($this->collector, $this->sender, true, ['_profiler', '_wdt']);

        $request = Request::create('/_profiler/abcdef', 'GET');
        $request->attributes->set('_route', '_profiler');
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onRequest($event);

        $this->assertFalse($this->collector->hasTransaction());
    }

    public function testOnRequestSkipsWhenDisabled(): void
    {
        $subscriber = new RequestSubscriber($this->collector, $this->sender, false);

        $request = Request::create('/api/users', 'GET');
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onRequest($event);

        $this->assertFalse($this->collector->hasTransaction());
    }

    public function testOnTerminateSendsTransactionWithOkStatus(): void
    {
        $subscriber = new RequestSubscriber($this->collector, $this->sender, true);

        $request = Request::create('/api/users', 'GET');
        $request->attributes->set('_route', 'api_users_list');
        $requestEvent = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onRequest($requestEvent);

        $response = new Response('', 200);
        $terminateEvent = new TerminateEvent($this->kernel, $request, $response);

        $this->mockClient->expects($this->once())
            ->method('sendTransaction')
            ->with($this->callback(function ($payload) {
                return 'ok' === $payload['transaction']['status']
                    && '200' === $payload['transaction']['tags']['http.status_code'];
            }));

        $subscriber->onTerminate($terminateEvent);
    }

    public function testOnTerminateSendsErrorStatusFor5xx(): void
    {
        $subscriber = new RequestSubscriber($this->collector, $this->sender, true);

        $request = Request::create('/api/users', 'GET');
        $requestEvent = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onRequest($requestEvent);

        $response = new Response('', 500);
        $terminateEvent = new TerminateEvent($this->kernel, $request, $response);

        $this->mockClient->expects($this->once())
            ->method('sendTransaction')
            ->with($this->callback(function ($payload) {
                return 'error' === $payload['transaction']['status'];
            }));

        $subscriber->onTerminate($terminateEvent);
    }

    public function testOnTerminateNoOpWhenNoTransaction(): void
    {
        $subscriber = new RequestSubscriber($this->collector, $this->sender, true);

        $request = Request::create('/api/users', 'GET');
        $response = new Response('', 200);
        $terminateEvent = new TerminateEvent($this->kernel, $request, $response);

        $this->mockClient->expects($this->never())->method('sendTransaction');

        $subscriber->onTerminate($terminateEvent);
    }

    public function testGetSubscribedEventsReturnsCorrectPriorities(): void
    {
        $events = RequestSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey('kernel.request', $events);
        $this->assertArrayHasKey('kernel.terminate', $events);
        $this->assertSame(['onRequest', 4096], $events['kernel.request']);
        $this->assertSame(['onTerminate', -4096], $events['kernel.terminate']);
    }
}
