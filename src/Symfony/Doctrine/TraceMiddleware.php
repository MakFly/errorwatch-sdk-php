<?php

namespace ErrorWatch\Symfony\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use ErrorWatch\Symfony\Profiler\RequestProfile;
use ErrorWatch\Symfony\Service\BreadcrumbService;
use ErrorWatch\Symfony\Service\TransactionCollector;

final class TraceMiddleware implements Middleware
{
    public function __construct(
        private readonly TransactionCollector $collector,
        private readonly bool $logQueries,
        private readonly ?BreadcrumbService $breadcrumbService = null,
        private readonly ?RequestProfile $profile = null,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        return new TraceDriver($driver, $this->collector, $this->logQueries, $this->breadcrumbService, $this->profile);
    }
}
