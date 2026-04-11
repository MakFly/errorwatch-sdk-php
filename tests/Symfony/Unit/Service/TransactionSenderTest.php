<?php

namespace ErrorWatch\Symfony\Tests\Unit\Service;

use ErrorWatch\Symfony\Http\MonitoringClientInterface;
use ErrorWatch\Symfony\Model\Transaction;
use ErrorWatch\Symfony\Service\TransactionSender;
use PHPUnit\Framework\TestCase;

final class TransactionSenderTest extends TestCase
{
    private MonitoringClientInterface $mockClient;

    protected function setUp(): void
    {
        $this->mockClient = $this->createMock(MonitoringClientInterface::class);
    }

    public function testSendCallsClientWithCorrectPayload(): void
    {
        $sender = new TransactionSender(
            client: $this->mockClient,
            enabled: true,
            environment: 'production',
        );

        $txn = new Transaction('GET /users');
        $txn->finish();

        $this->mockClient->expects($this->once())
            ->method('sendTransaction')
            ->with($this->callback(function ($payload) {
                return isset($payload['transaction'])
                    && isset($payload['env'])
                    && 'production' === $payload['env']
                    && 'GET /users' === $payload['transaction']['name']
                    && 'http.server' === $payload['transaction']['op'];
            }));

        $sender->send($txn);
    }

    public function testSendSkipsWhenDisabled(): void
    {
        $sender = new TransactionSender(
            client: $this->mockClient,
            enabled: false,
            environment: 'test',
        );

        $this->mockClient->expects($this->never())
            ->method('sendTransaction');

        $txn = new Transaction('GET /users');
        $txn->finish();
        $sender->send($txn);
    }

    public function testSendFailsSilently(): void
    {
        $sender = new TransactionSender(
            client: $this->mockClient,
            enabled: true,
            environment: 'test',
        );

        $this->mockClient->expects($this->once())
            ->method('sendTransaction')
            ->willThrowException(new \RuntimeException('Network error'));

        $txn = new Transaction('GET /users');
        $txn->finish();

        // Should not throw
        $sender->send($txn);
    }
}
