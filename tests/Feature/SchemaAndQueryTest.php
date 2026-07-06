<?php

declare(strict_types=1);

namespace AmazingBV\GoogleSheetsDatabaseDriver\Tests\Feature;

use AmazingBV\GoogleSheetsDatabaseDriver\Tests\TestCase;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SchemaAndQueryTest extends TestCase
{
    public function test_schema_builder_and_query_builder_crud_work_together(): void
    {
        Schema::connection('google-sheets')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->integer('age')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        $users = DB::connection('google-sheets')->table('users');

        $firstId = $users->insertGetId([
            'name' => 'Taylor',
            'age' => 36,
        ]);

        $users->insert([
            [
                'name' => 'Abigail',
                'age' => 29,
                'is_active' => false,
            ],
            [
                'name' => 'Mohamed',
                'age' => 41,
            ],
        ]);

        $this->assertSame(1, $firstId);
        $this->assertSame(3, DB::connection('google-sheets')->table('users')->count());
        $this->assertSame(['Abigail', 'Taylor'], DB::connection('google-sheets')->table('users')->where('age', '<', 40)->orderBy('name')->pluck('name')->all());
        $this->assertSame(106.0, (float) DB::connection('google-sheets')->table('users')->sum('age'));

        $updated = DB::connection('google-sheets')->table('users')->where('name', 'Taylor')->limit(1)->update([
            'age' => 37,
        ]);

        $deleted = DB::connection('google-sheets')->table('users')->whereLike('name', 'Abig%')->delete();

        $this->assertSame(1, $updated);
        $this->assertSame(1, $deleted);
        $this->assertSame(37, DB::connection('google-sheets')->table('users')->find(1)->age);
        $this->assertSame(2, DB::connection('google-sheets')->table('users')->count());

        Schema::connection('google-sheets')->table('users', function (Blueprint $table): void {
            $table->string('nickname')->nullable();
            $table->renameColumn('nickname', 'display_name');
        });

        $this->assertSame(['id', 'name', 'age', 'is_active', 'created_at', 'updated_at', 'deleted_at', 'display_name'], Schema::connection('google-sheets')->getColumnListing('users'));
    }

    public function test_mutations_are_serialized_with_table_locks(): void
    {
        Schema::connection('google-sheets')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        $lock = Cache::store('array')
            ->getStore()
            ->lock('google-sheets-dbal:spreadsheet-test:lock:users', 30);

        $this->assertTrue($lock->get());

        try {
            $this->expectException(LockTimeoutException::class);

            DB::connection('google-sheets')->table('users')->insert([
                'name' => 'Taylor',
            ]);
        } finally {
            $lock->release();
        }
    }

    public function test_mixed_and_or_wheres_follow_sql_precedence(): void
    {
        Schema::connection('google-sheets')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('role');
            $table->boolean('active');
            $table->string('country');
        });

        DB::connection('google-sheets')->table('users')->insert([
            ['role' => 'admin', 'active' => false, 'country' => 'BE'],
            ['role' => 'member', 'active' => true, 'country' => 'NL'],
            ['role' => 'member', 'active' => true, 'country' => 'BE'],
            ['role' => 'member', 'active' => false, 'country' => 'NL'],
        ]);

        $ungrouped = DB::connection('google-sheets')
            ->table('users')
            ->where('role', 'admin')
            ->orWhere('active', true)
            ->where('country', 'NL')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $grouped = DB::connection('google-sheets')
            ->table('users')
            ->where(function ($query): void {
                $query->where('role', 'admin')->orWhere('active', true);
            })
            ->where('country', 'NL')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([1, 2], $ungrouped);
        $this->assertSame([2], $grouped);
    }

    public function test_aggregates_are_calculated_before_limit_and_offset_are_applied(): void
    {
        Schema::connection('google-sheets')->create('scores', function (Blueprint $table): void {
            $table->id();
            $table->integer('points');
        });

        DB::connection('google-sheets')->table('scores')->insert([
            ['points' => 10],
            ['points' => 20],
            ['points' => 30],
        ]);

        $query = DB::connection('google-sheets')
            ->table('scores')
            ->where('points', '>', 0)
            ->offset(1)
            ->limit(1);

        $this->assertSame(3, $query->count());
        $this->assertSame(60, (int) $query->sum('points'));
        $this->assertSame(20.0, (float) $query->avg('points'));
    }
}
