<?php

declare(strict_types=1);

namespace AmazingNL\GoogleSheetsDBAL\Tests;

use AmazingNL\GoogleSheetsDBAL\GoogleSheetsServiceProvider;
use AmazingNL\GoogleSheetsDBAL\Tests\Support\InMemorySheetsTransport;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        InMemorySheetsTransport::reset();
        DB::purge('google-sheets');
    }

    protected function getPackageProviders($app): array
    {
        return [
            GoogleSheetsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'google-sheets');
        $app['config']->set('database.migrations', [
            'table' => 'migrations',
        ]);
        $app['config']->set('database.connections.google-sheets', [
            'driver' => 'google-sheets',
            'database' => 'spreadsheet-test',
            'cache_store' => 'array',
            'cache_ttl' => 60,
            'schema_sheet' => '__sheetsdbal_schema',
            'migrations_table' => 'migrations',
            'migrations_sheet' => '__sheetsdbal_migrations',
            'transport' => InMemorySheetsTransport::class,
        ]);
    }
}
