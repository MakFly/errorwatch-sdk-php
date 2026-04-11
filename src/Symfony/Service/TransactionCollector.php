<?php

namespace ErrorWatch\Symfony\Service;

use ErrorWatch\Symfony\Model\Span;
use ErrorWatch\Symfony\Model\Transaction;

final class TransactionCollector
{
    private ?Transaction $current = null;
    private ?QueryAnalyzer $queryAnalyzer = null;

    public function setQueryAnalyzer(QueryAnalyzer $queryAnalyzer): void
    {
        $this->queryAnalyzer = $queryAnalyzer;
    }

    public function startTransaction(string $name, string $op = 'http.server'): Transaction
    {
        $this->current = new Transaction($name, $op);

        return $this->current;
    }

    public function getCurrentTransaction(): ?Transaction
    {
        return $this->current;
    }

    public function addSpan(Span $span): void
    {
        if (null === $this->current) {
            return;
        }

        $this->current->addSpan($span);
    }

    public function finishTransaction(string $status = 'ok'): ?Transaction
    {
        if (null === $this->current) {
            return null;
        }

        $this->current->setStatus($status);
        $this->current->finish();

        if (null !== $this->queryAnalyzer) {
            $analysis = $this->queryAnalyzer->analyze($this->current);
            foreach ($analysis['tags'] as $key => $value) {
                $this->current->setTag($key, $value);
            }
            foreach ($analysis['data'] as $key => $value) {
                $this->current->setData($key, $value);
            }
        }

        $txn = $this->current;
        $this->current = null;

        return $txn;
    }

    public function hasTransaction(): bool
    {
        return null !== $this->current;
    }

    public function reset(): void
    {
        $this->current = null;
    }
}
