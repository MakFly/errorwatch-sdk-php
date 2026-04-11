<?php

namespace ErrorWatch\Symfony\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use ErrorWatch\Symfony\Service\BreadcrumbService;
use ErrorWatch\Symfony\Service\TransactionCollector;

final class TraceMiddleware implements Middleware
{
    public function __construct(
        private readonly TransactionCollector $collector,
        private readonly bool $logQueries,
        private readonly ?BreadcrumbService $breadcrumbService = null,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        return new TraceDriver($driver, $this->collector, $this->logQueries, $this->breadcrumbService);
    }
}
