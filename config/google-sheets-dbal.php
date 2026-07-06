<?php

declare(strict_types=1);

use AmazingBV\GoogleSheetsDatabaseDriver\Transports\GoogleSheetsApiTransport;

return [
    'connection' => [
        'driver' => 'google-sheets',
        'database' => env('DB_DATABASE'),
        'prefix' => '',
        'credentials_path' => env('GOOGLE_SHEETS_CREDENTIALS_PATH'),
        'cache_store' => env('GOOGLE_SHEETS_CACHE_STORE'),
        'cache_ttl' => (int) env('GOOGLE_SHEETS_CACHE_TTL', 60),
        'lock_ttl_seconds' => (int) env('GOOGLE_SHEETS_LOCK_TTL_SECONDS', 30),
        'lock_wait_seconds' => (int) env('GOOGLE_SHEETS_LOCK_WAIT_SECONDS', 10),
        'quota_retry_attempts' => (int) env('GOOGLE_SHEETS_QUOTA_RETRY_ATTEMPTS', 5),
        'quota_retry_base_delay_ms' => (int) env('GOOGLE_SHEETS_QUOTA_RETRY_BASE_DELAY_MS', 1000),
        'quota_retry_max_delay_ms' => (int) env('GOOGLE_SHEETS_QUOTA_RETRY_MAX_DELAY_MS', 10000),
        'read_requests_per_minute' => (int) env('GOOGLE_SHEETS_READ_REQUESTS_PER_MINUTE', 50),
        'write_requests_per_minute' => (int) env('GOOGLE_SHEETS_WRITE_REQUESTS_PER_MINUTE', 45),
        'schema_sheet' => '__sheetsdbal_schema',
        'migrations_table' => env('GOOGLE_SHEETS_MIGRATIONS_TABLE', 'migrations'),
        'migrations_sheet' => '__sheetsdbal_migrations',
        'transport' => GoogleSheetsApiTransport::class,
    ],
];
