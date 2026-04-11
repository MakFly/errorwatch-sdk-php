<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Event;

enum Severity: string
{
    case FATAL   = 'fatal';
    case ERROR   = 'error';
    case WARNING = 'warning';
    case INFO    = 'info';
    case DEBUG   = 'debug';
}
