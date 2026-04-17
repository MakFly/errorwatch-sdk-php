<?php

namespace ErrorWatch\Symfony\Messenger;

use ErrorWatch\Symfony\Model\Span;
use ErrorWatch\Symfony\Service\TransactionCollector;
use ErrorWatch\Symfony\Service\TransactionSender;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * Messenger middleware that emits APM spans for dispatch/consume.
 *
 * Dispatch side: adds a `queue.publish` span to the currently open
 * transaction (typically an HTTP request that dispatches a message).
 *
 * Consumer side: opens a dedicated `queue.process` transaction for the
 * message handling, so each consumed message produces an independent
 * trace with spans (DB, cache, http, etc.) recorded by the rest of the
 * SDK during handler execution.
 */
final class TracingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TransactionCollector $collector,
        private readonly TransactionSender $sender,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $messageClass = get_class($envelope->getMessage());
        $receivedStamp = $envelope->last(ReceivedStamp::class);
        $isConsume = $receivedStamp instanceof ReceivedStamp;

        return $isConsume
            ? $this->handleConsume($envelope, $stack, $messageClass, $receivedStamp)
            : $this->handleDispatch($envelope, $stack, $messageClass);
    }

    private function handleDispatch(Envelope $envelope, StackInterface $stack, string $messageClass): Envelope
    {
        if (!$this->collector->hasTransaction()) {
            return $stack->next()->handle($envelope, $stack);
        }

        $span = new Span('queue.publish', $messageClass);
        $span->setData('messaging.system', 'symfony.messenger');
        $span->setData('messaging.message.class', $messageClass);

        try {
            $next = $stack->next()->handle($envelope, $stack);

            $transportId = $next->last(TransportMessageIdStamp::class);
            if ($transportId instanceof TransportMessageIdStamp) {
                $span->setData('messaging.message.id', (string) $transportId->getId());
            }

            return $next;
        } catch (\Throwable $e) {
            $span->setStatus('error');
            $span->setData('error.message', $e->getMessage());
            throw $e;
        } finally {
            $span->finish();
            $this->collector->addSpan($span);
        }
    }

    private function handleConsume(
        Envelope $envelope,
        StackInterface $stack,
        string $messageClass,
        ReceivedStamp $receivedStamp,
    ): Envelope {
        // Reset any leftover state from a previous message in the same worker
        // process — the worker loop is long-lived and reuses services.
        $this->collector->reset();

        $transportName = $receivedStamp->getTransportName();
        $transaction = $this->collector->startTransaction(
            sprintf('messenger.%s', $messageClass),
            'queue.process',
        );
        $transaction->setData('messaging.system', 'symfony.messenger');
        $transaction->setData('messaging.message.class', $messageClass);
        $transaction->setData('messaging.transport', $transportName);
        $transaction->setTag('messenger.transport', $transportName);

        $transportId = $envelope->last(TransportMessageIdStamp::class);
        if ($transportId instanceof TransportMessageIdStamp) {
            $transaction->setData('messaging.message.id', (string) $transportId->getId());
        }

        $status = 'ok';
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (\Throwable $e) {
            $status = 'error';
            $transaction->setData('error.message', $e->getMessage());
            throw $e;
        } finally {
            $finished = $this->collector->finishTransaction($status);
            if (null !== $finished) {
                $this->sender->send($finished);
            }
        }
    }
}
