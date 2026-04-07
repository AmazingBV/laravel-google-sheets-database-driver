<?php

declare(strict_types=1);

namespace AmazingBV\GoogleSheetsDatabaseDriver\Tests\Feature;

use AmazingBV\GoogleSheetsDatabaseDriver\Tests\Support\InMemorySheetsTransport;
use AmazingBV\GoogleSheetsDatabaseDriver\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

class SheetsInstallCommandTest extends TestCase
{
    public function test_install_command_initializes_system_sheets_and_syncs_existing_tabs(): void
    {
        InMemorySheetsTransport::seed('spreadsheet-test', [
            'contacts' => [
                'rows' => [
                    ['id', 'name'],
                    [1, 'Taylor'],
                ],
            ],
        ]);

        $this->artisan('sheets:install')
            ->assertSuccessful();

        $snapshot = InMemorySheetsTransport::snapshot('spreadsheet-test');

        $this->assertArrayHasKey('__sheetsdbal_schema', $snapshot);
        $this->assertArrayHasKey('__sheetsdbal_migrations', $snapshot);
        $this->assertTrue($snapshot['__sheetsdbal_schema']['hidden']);
        $this->assertTrue($snapshot['__sheetsdbal_migrations']['hidden']);
        $this->assertTrue(Schema::connection('google-sheets')->hasTable('contacts'));
        $this->assertSame(['id', 'name'], Schema::connection('google-sheets')->getColumnListing('contacts'));
    }

    public function test_install_command_does_not_resolve_the_default_cache_store_when_no_store_is_configured(): void
    {
        config()->set('database.connections.google-sheets.cache_store', null);
        config()->set('cache.default', 'database');
        config()->set('cache.stores.database', [
            'driver' => 'database',
            'connection' => 'google-sheets',
            'table' => 'cache',
        ]);

        $this->artisan('sheets:install')
            ->assertSuccessful();

        $this->assertTrue(Schema::connection('google-sheets')->hasTable('migrations'));
    }
}
