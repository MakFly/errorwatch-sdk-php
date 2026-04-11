<?php

namespace ErrorWatch\Symfony\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use ErrorWatch\Symfony\Model\Breadcrumb;
use ErrorWatch\Symfony\Model\Span;
use ErrorWatch\Symfony\Service\BreadcrumbService;
use ErrorWatch\Symfony\Service\TransactionCollector;

final class TraceConnection extends AbstractConnectionMiddleware
{
    public function __construct(
        \Doctrine\DBAL\Driver\Connection $connection,
        private readonly TransactionCollector $collector,
        private readonly bool $logQueries,
        private readonly ?BreadcrumbService $breadcrumbService = null,
    ) {
        parent::__construct($connection);
    }

    public function prepare(string $sql): StatementInterface
    {
        return new TraceStatement(parent::prepare($sql), $this->collector, $sql, $this->logQueries, $this->breadcrumbService);
    }

    public function query(string $sql): Result
    {
        $span = new Span('db.sql.query', $this->logQueries ? self::sanitize($sql) : null);

        try {
            $result = parent::query($sql);
            $span->setStatus('ok');

            return $result;
        } catch (\Throwable $e) {
            $span->setStatus('error');
            throw $e;
        } finally {
            $span->finish();
            $this->collector->addSpan($span);
            $this->breadcrumbService?->add(Breadcrumb::console(
                sprintf('SQL: %s', self::sanitize($sql)),
            ));
        }
    }

    public static function sanitize(string $sql): string
    {
        return preg_replace(["/\'[^\']*\'/", '/\b\d+\b/'], '?', $sql);
    }
}
