<?php

return [
    /*
    |--------------------------------------------------------------------------
    | ErrorWatch SDK Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration options for the ErrorWatch Laravel SDK.
    | You can publish this file to your application using:
    | php artisan vendor:publish --tag=errorwatch-config
    |
    */

    // Core settings
    'enabled' => env('ERRORWATCH_ENABLED', true),
    'endpoint' => env('ERRORWATCH_ENDPOINT'),
    'api_key' => env('ERRORWATCH_API_KEY'),
    'environment' => env('APP_ENV', 'production'),
    'release' => env('APP_VERSION'),

    // Transport/resilience configuration
    'transport' => [
        'timeout' => 5,
        'retry_attempts' => env('ERRORWATCH_RETRY_ATTEMPTS', 2),
        'circuit_breaker_threshold' => 5,
        'circuit_breaker_cooldown' => 60,
    ],

    // Session replay configuration
    'replay' => [
        'enabled' => env('ERRORWATCH_REPLAY_ENABLED', false),
        'sample_rate' => env('ERRORWATCH_REPLAY_SAMPLE_RATE', 0.1),
        'debug' => env('ERRORWATCH_REPLAY_DEBUG', false),
    ],

    // Breadcrumbs configuration
    'breadcrumbs' => [
        'enabled' => true,
        'max_count' => 100,
    ],

    // User context configuration
    'user_context' => [
        'enabled' => true,
        'capture_ip' => true,
    ],

    // Console commands configuration
    'console' => [
        'enabled' => true,
        'capture_exit_codes' => true,
    ],

    // Queue/Jobs configuration (equivalent to Symfony messenger)
    'queue' => [
        'enabled' => true,
        'capture_retries' => false,
    ],

    // PHP deprecations configuration
    'deprecations' => [
        'enabled' => false,
    ],

    // Security/Auth events configuration
    'security' => [
        'enabled' => true,
        'capture_login_success' => false,
    ],

    // APM (Application Performance Monitoring) configuration
    'apm' => [
        'enabled' => true,
        'request_tracking' => true,
        'sample_rate' => env('ERRORWATCH_APM_SAMPLE_RATE', 1.0),

        // Eloquent queries configuration (equivalent to Doctrine in Symfony)
        'eloquent' => [
            'enabled' => true,
            'log_queries' => true,
        ],

        // HTTP client configuration (Laravel Http facade)
        'http_client' => [
            'enabled' => true,
            'capture_errors_as_breadcrumbs' => true,
        ],

        // Laravel Cache instrumentation (Cache facade + container resolves)
        // Emits cache.get_item / cache.put_item / cache.remove_item spans
        // for every cache event fired during a tracked transaction.
        'cache' => [
            'enabled' => true,
        ],

        // Performance thresholds
        'n_plus_one_threshold' => 5,
        'slow_query_threshold_ms' => 500,

        // Routes excluded from APM tracking
        'excluded_routes' => ['telescope/*', 'horizon/*', '_ignition/*'],
    ],

    // Laravel logging integration (replaces Monolog handler)
    'logging' => [
        'enabled' => env('ERRORWATCH_LOGGING_ENABLED', true),
        'level' => env('ERRORWATCH_LOG_LEVEL', 'error'),
        'excluded_channels' => ['errorwatch'],
    ],

    // Exceptions handling configuration
    'exceptions' => [
        'enabled' => env('ERRORWATCH_EXCEPTIONS_ENABLED', true),
    ],

    // Live logs streaming configuration
    'logs' => [
        'enabled' => true,
        'level' => 'debug',
        'excluded_channels' => [],
    ],

    // Callbacks for modifying events before sending
    'before_send' => null,
    'before_send_transaction' => null,
];
