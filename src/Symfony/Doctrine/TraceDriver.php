<?php

namespace ErrorWatch\Symfony\Doctrine;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use ErrorWatch\Symfony\Service\BreadcrumbService;
use ErrorWatch\Symfony\Service\TransactionCollector;

final class TraceDriver extends AbstractDriverMiddleware
{
    public function __construct(
        \Doctrine\DBAL\Driver $driver,
        private readonly TransactionCollector $collector,
        private readonly bool $logQueries,
        private readonly ?BreadcrumbService $breadcrumbService = null,
    ) {
        parent::__construct($driver);
    }

    public function connect(array $params): ConnectionInterface
    {
        return new TraceConnection(parent::connect($params), $this->collector, $this->logQueries, $this->breadcrumbService);
    }
}
