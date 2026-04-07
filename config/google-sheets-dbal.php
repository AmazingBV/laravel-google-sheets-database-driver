<?php

declare(strict_types=1);

use AmazingNL\GoogleSheetsDBAL\Transports\GoogleSheetsApiTransport;

return [
    'connection' => [
        'driver' => 'google-sheets',
        'database' => env('DB_DATABASE'),
        'prefix' => '',
        'credentials_path' => env('GOOGLE_SHEETS_CREDENTIALS_PATH'),
        'cache_store' => env('GOOGLE_SHEETS_CACHE_STORE'),
        'cache_ttl' => (int) env('GOOGLE_SHEETS_CACHE_TTL', 60),
        'schema_sheet' => '__sheetsdbal_schema',
        'migrations_table' => env('GOOGLE_SHEETS_MIGRATIONS_TABLE', 'migrations'),
        'migrations_sheet' => '__sheetsdbal_migrations',
        'transport' => GoogleSheetsApiTransport::class,
    ],
];
