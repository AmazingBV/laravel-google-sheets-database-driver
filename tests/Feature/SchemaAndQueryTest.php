<?php

declare(strict_types=1);

namespace AmazingNL\GoogleSheetsDBAL\Tests\Feature;

use AmazingNL\GoogleSheetsDBAL\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
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
}
