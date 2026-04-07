<?php

declare(strict_types=1);

namespace AmazingBV\GoogleSheetsDatabaseDriver\Tests\Feature;

use AmazingBV\GoogleSheetsDatabaseDriver\Tests\Support\InMemorySheetsTransport;
use AmazingBV\GoogleSheetsDatabaseDriver\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DatabaseIndexTest extends TestCase
{
    public function test_database_index_sheet_is_created_as_the_first_tab_and_lists_tables(): void
    {
        Schema::connection('google-sheets')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::connection('google-sheets')->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });

        $snapshot = InMemorySheetsTransport::snapshot('spreadsheet-test');
        $sheetNames = array_keys($snapshot);

        $this->assertSame('Database Index', $sheetNames[0]);
        $this->assertSame('Database Index', $snapshot['Database Index']['rows'][0][0]);
        $this->assertCount(3, $snapshot['Database Index']['rows']);
        $this->assertStringContainsString('users', $snapshot['Database Index']['rows'][1][0]);
        $this->assertStringContainsString('posts', $snapshot['Database Index']['rows'][2][0]);
    }

    public function test_database_index_sheet_updates_when_tables_are_renamed_or_dropped(): void
    {
        Schema::connection('google-sheets')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::connection('google-sheets')->rename('users', 'members');
        Schema::connection('google-sheets')->drop('members');

        $rows = InMemorySheetsTransport::snapshot('spreadsheet-test')['Database Index']['rows'];

        $this->assertSame([['Database Index']], $rows);
    }
}
