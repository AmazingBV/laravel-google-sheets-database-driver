<?php

declare(strict_types=1);

namespace AmazingBV\GoogleSheetsDatabaseDriver\Tests\Feature;

use AmazingBV\GoogleSheetsDatabaseDriver\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class JoinAndMigrationTest extends TestCase
{
    public function test_simple_inner_joins_are_evaluated_in_memory(): void
    {
        Schema::connection('google-sheets')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::connection('google-sheets')->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id');
            $table->string('title');
        });

        DB::connection('google-sheets')->table('users')->insert([
            ['id' => 1, 'name' => 'Taylor'],
            ['id' => 2, 'name' => 'Abigail'],
        ]);

        DB::connection('google-sheets')->table('posts')->insert([
            ['id' => 1, 'user_id' => 1, 'title' => 'First'],
            ['id' => 2, 'user_id' => 2, 'title' => 'Second'],
        ]);

        $joined = DB::connection('google-sheets')
            ->table('posts')
            ->join('users', 'users.id', '=', 'posts.user_id')
            ->select('posts.title as title', 'users.name as author')
            ->orderBy('posts.id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $this->assertSame([
            ['title' => 'First', 'author' => 'Taylor'],
            ['title' => 'Second', 'author' => 'Abigail'],
        ], $joined);
    }

    public function test_migrate_command_can_use_the_google_sheets_connection(): void
    {
        $this->artisan('migrate', [
            '--database' => 'google-sheets',
            '--path' => realpath(__DIR__.'/../Fixtures/migrations'),
            '--realpath' => true,
        ])->assertSuccessful();

        $this->assertTrue(Schema::connection('google-sheets')->hasTable('products'));
        $this->assertSame(['id', 'name', 'price'], Schema::connection('google-sheets')->getColumnListing('products'));
    }

    public function test_migrate_command_supports_default_schema_facade_usage_with_database_option(): void
    {
        $this->artisan('migrate', [
            '--database' => 'google-sheets',
            '--path' => realpath(__DIR__.'/../Fixtures/default-migrations'),
            '--realpath' => true,
        ])->assertSuccessful();

        $this->assertTrue(Schema::connection('google-sheets')->hasTable('users'));
        $this->assertTrue(Schema::connection('google-sheets')->hasTable('cache'));
        $this->assertTrue(Schema::connection('google-sheets')->hasTable('sessions'));
        $this->assertSame(['id', 'name', 'email', 'password', 'created_at', 'updated_at'], Schema::connection('google-sheets')->getColumnListing('users'));
        $this->assertSame(['key', 'value', 'expiration'], Schema::connection('google-sheets')->getColumnListing('cache'));
        $this->assertSame(['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity'], Schema::connection('google-sheets')->getColumnListing('sessions'));
    }
}
