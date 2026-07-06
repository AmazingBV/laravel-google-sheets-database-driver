<?php

declare(strict_types=1);

namespace AmazingBV\GoogleSheetsDatabaseDriver\Tests\Feature;

use AmazingBV\GoogleSheetsDatabaseDriver\Exceptions\GoogleSheetsException;
use AmazingBV\GoogleSheetsDatabaseDriver\Tests\Support\InMemorySheetsTransport;
use AmazingBV\GoogleSheetsDatabaseDriver\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QuotaAwarenessTest extends TestCase
{
    public function test_repeated_sheet_introspection_reuses_cached_transport_reads_within_one_connection(): void
    {
        InMemorySheetsTransport::seed('spreadsheet-test', [
            'users' => [
                'rows' => [
                    ['id', 'name'],
                    [1, 'Taylor'],
                ],
            ],
        ]);

        $database = DB::connection('google-sheets')->getGoogleSheetsDatabase();

        $this->assertTrue($database->hasTable('users'));
        $this->assertTrue($database->hasTable('users'));
        $this->assertSame(['id', 'name'], $database->getColumnListing('users'));
        $this->assertSame(['id', 'name'], $database->getColumnListing('users'));

        $calls = InMemorySheetsTransport::calls();

        $this->assertSame(1, $calls['listSheets']);
        $this->assertSame(1, $calls['getSheetValues']);
    }

    public function test_stale_schema_metadata_is_removed_when_the_physical_sheet_was_deleted_manually(): void
    {
        InMemorySheetsTransport::seed('spreadsheet-test', [
            '__sheetsdbal_schema' => [
                'hidden' => true,
                'rows' => [
                    ['table', 'columns', 'next_id', 'hidden'],
                    ['cache', '[{"name":"key","type":"string","nullable":false,"default":null,"auto_increment":false,"primary":true}]', 1, false],
                ],
            ],
        ]);

        $database = DB::connection('google-sheets')->getGoogleSheetsDatabase();

        $this->assertFalse($database->hasTable('cache'));

        Schema::connection('google-sheets')->create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->text('value');
        });

        $this->assertTrue($database->hasTable('cache'));
        $this->assertSame(['key', 'value'], Schema::connection('google-sheets')->getColumnListing('cache'));
    }

    public function test_empty_physical_tabs_are_not_reported_as_valid_tables(): void
    {
        InMemorySheetsTransport::seed('spreadsheet-test', [
            'empty' => [
                'rows' => [],
            ],
            '__sheetsdbal_migrations' => [
                'hidden' => true,
                'rows' => [],
            ],
        ]);

        $database = DB::connection('google-sheets')->getGoogleSheetsDatabase();

        $this->assertFalse($database->hasTable('empty'));
        $this->assertFalse($database->hasTable('migrations'));

        $database->ensureSystemSheets();

        $snapshot = InMemorySheetsTransport::snapshot('spreadsheet-test');

        $this->assertTrue($database->hasTable('migrations'));
        $this->assertSame(['id', 'migration', 'batch', 'tables'], $snapshot['__sheetsdbal_migrations']['rows'][0]);
    }

    public function test_persistent_cache_does_not_reuse_stale_table_snapshots_across_connections(): void
    {
        Schema::connection('google-sheets')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        DB::connection('google-sheets')->table('users')->insert([
            'name' => 'Taylor',
        ]);

        DB::connection('google-sheets')->table('users')->get();

        $snapshot = InMemorySheetsTransport::snapshot('spreadsheet-test');
        $snapshot['users']['rows'][] = [99, 'Manual'];

        InMemorySheetsTransport::seed('spreadsheet-test', $snapshot);
        DB::purge('google-sheets');

        DB::connection('google-sheets')->table('users')->insert([
            'name' => 'Abigail',
        ]);

        $rows = InMemorySheetsTransport::snapshot('spreadsheet-test')['users']['rows'];

        $this->assertSame([
            ['id', 'name'],
            [1, 'Taylor'],
            [99, 'Manual'],
            [2, 'Abigail'],
        ], $rows);
    }

    public function test_stale_create_table_migration_log_is_pruned_so_migrate_recreates_missing_tabs(): void
    {
        InMemorySheetsTransport::seed('spreadsheet-test', [
            '__sheetsdbal_schema' => [
                'hidden' => true,
                'rows' => [
                    ['table', 'columns', 'next_id', 'hidden'],
                    ['migrations', '[{"name":"id","type":"integer","nullable":false,"default":null,"auto_increment":true,"primary":true},{"name":"migration","type":"string","nullable":false,"default":null,"auto_increment":false,"primary":false},{"name":"batch","type":"integer","nullable":false,"default":null,"auto_increment":false,"primary":false}]', 2, true],
                ],
            ],
            '__sheetsdbal_migrations' => [
                'hidden' => true,
                'rows' => [
                    ['id', 'migration', 'batch'],
                    [1, '0001_01_01_000001_create_cache_table', 1],
                ],
            ],
        ]);

        $this->artisan('migrate', [
            '--database' => 'google-sheets',
            '--path' => realpath(__DIR__.'/../Fixtures/stale-migration-log'),
            '--realpath' => true,
        ])->assertSuccessful();

        $this->assertTrue(Schema::connection('google-sheets')->hasTable('cache'));
        $this->assertSame(['key', 'value', 'expiration'], Schema::connection('google-sheets')->getColumnListing('cache'));
    }

    public function test_partially_missing_tracked_migration_tables_fail_with_explicit_repair_error(): void
    {
        InMemorySheetsTransport::seed('spreadsheet-test', [
            '__sheetsdbal_schema' => [
                'hidden' => true,
                'rows' => [
                    ['table', 'columns', 'next_id', 'hidden'],
                    ['migrations', '[{"name":"id","type":"integer","nullable":false,"default":null,"auto_increment":true,"primary":true},{"name":"migration","type":"string","nullable":false,"default":null,"auto_increment":false,"primary":false},{"name":"batch","type":"integer","nullable":false,"default":null,"auto_increment":false,"primary":false},{"name":"tables","type":"json","nullable":true,"default":null,"auto_increment":false,"primary":false}]', 2, true],
                    ['users', '[{"name":"id","type":"integer","nullable":false,"default":null,"auto_increment":true,"primary":true},{"name":"name","type":"string","nullable":false,"default":null,"auto_increment":false,"primary":false},{"name":"email","type":"string","nullable":false,"default":null,"auto_increment":false,"primary":false},{"name":"password","type":"string","nullable":false,"default":null,"auto_increment":false,"primary":false},{"name":"created_at","type":"timestamp","nullable":true,"default":null,"auto_increment":false,"primary":false},{"name":"updated_at","type":"timestamp","nullable":true,"default":null,"auto_increment":false,"primary":false}]', 1, false],
                ],
            ],
            '__sheetsdbal_migrations' => [
                'hidden' => true,
                'rows' => [
                    ['id', 'migration', 'batch', 'tables'],
                    [1, '0001_01_01_000000_create_users_table', 1, '["cache","sessions","users"]'],
                ],
            ],
            'users' => [
                'rows' => [
                    ['id', 'name', 'email', 'password', 'created_at', 'updated_at'],
                ],
            ],
        ]);

        $this->expectException(GoogleSheetsException::class);
        $this->expectExceptionMessage('Cannot auto-repair migration [0001_01_01_000000_create_users_table]');

        $this->artisan('migrate', [
            '--database' => 'google-sheets',
            '--path' => realpath(__DIR__.'/../Fixtures/default-migrations'),
            '--realpath' => true,
        ]);
    }
}
