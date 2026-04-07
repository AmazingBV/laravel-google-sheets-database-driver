<?php

declare(strict_types=1);

namespace AmazingNL\GoogleSheetsDBAL\Tests\Feature;

use AmazingNL\GoogleSheetsDBAL\Tests\TestCase;
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
}
