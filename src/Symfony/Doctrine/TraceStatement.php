<?php

namespace ErrorWatch\Symfony\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use ErrorWatch\Symfony\Model\Breadcrumb;
use ErrorWatch\Symfony\Model\Span;
use ErrorWatch\Symfony\Service\BreadcrumbService;
use ErrorWatch\Symfony\Service\TransactionCollector;

final class TraceStatement extends AbstractStatementMiddleware
{
    public function __construct(
        StatementInterface $statement,
        private readonly TransactionCollector $collector,
        private readonly string $sql,
        private readonly bool $logQueries,
        private readonly ?BreadcrumbService $breadcrumbService = null,
    ) {
        parent::__construct($statement);
    }

    public function execute(mixed $params = null): Result
    {
        $span = new Span('db.sql.query', $this->logQueries ? TraceConnection::sanitize($this->sql) : null);

        try {
            $result = parent::execute($params);
            $span->setStatus('ok');

            return $result;
        } catch (\Throwable $e) {
            $span->setStatus('error');
            throw $e;
        } finally {
            $span->finish();
            $this->collector->addSpan($span);
            $this->breadcrumbService?->add(Breadcrumb::console(
                sprintf('SQL: %s', TraceConnection::sanitize($this->sql)),
            ));
        }
    }
}
