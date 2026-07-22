<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Query Monitor Master Switch
    |--------------------------------------------------------------------------
    |
    | This option may be used to disable all query monitoring functionality.
    | When disabled, no slow queries will be tracked or reported.
    |
    */
    'enabled' => env('QUERY_MONITOR_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Slow Query Threshold
    |--------------------------------------------------------------------------
    |
    | Queries exceeding this duration (in milliseconds) will be flagged as
    | slow and reported as handled exceptions. Adjust based on your
    | application's performance baseline.
    |
    */
    'threshold_ms' => env('QUERY_MONITOR_THRESHOLD_MS', 150),

    /*
    |--------------------------------------------------------------------------
    | Ignored Query Patterns
    |--------------------------------------------------------------------------
    |
    | SQL patterns that should be ignored when monitoring queries. Any query
    | whose SQL string contains one of these patterns will not be reported.
    | Useful for filtering out noise from framework-level queries.
    |
    */
    'ignore' => [
        'into "jobs"',
        'from "cache"',
        'into "cache"',
    ],
];
