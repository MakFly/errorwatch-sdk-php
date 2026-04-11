<?php

declare(strict_types=1);

namespace ErrorWatch\Sdk\Context;

class RuntimeContext
{
    public static function capture(): array
    {
        return [
            'runtime' => [
                'name'    => 'php',
                'version' => PHP_VERSION,
                'sapi'    => PHP_SAPI,
            ],
            'os' => [
                'name'    => PHP_OS_FAMILY,
                'release' => php_uname('r'),
            ],
        ];
    }
}
