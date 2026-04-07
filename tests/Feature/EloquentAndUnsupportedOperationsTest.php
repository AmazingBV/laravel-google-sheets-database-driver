<?php

declare(strict_types=1);

namespace AmazingNL\GoogleSheetsDBAL\Tests\Feature;

use AmazingNL\GoogleSheetsDBAL\Exceptions\UnsupportedSheetsOperation;
use AmazingNL\GoogleSheetsDBAL\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EloquentAndUnsupportedOperationsTest extends TestCase
{
    public function test_eloquent_models_can_create_update_and_soft_delete_records(): void
    {
        Schema::connection('google-sheets')->create('flights', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->integer('price');
            $table->timestamps();
            $table->softDeletes();
        });

        $flight = Flight::query()->create([
            'name' => 'AMS-LHR',
            'price' => 120,
        ]);

        $flight->price = 140;
        $flight->save();
        $flight->delete();

        $this->assertSame(1, $flight->id);
        $this->assertNotNull($flight->created_at);
        $this->assertSame(0, Flight::query()->count());
        $this->assertSame(1, Flight::withTrashed()->count());
    }

    public function test_transactions_throw_a_clear_exception(): void
    {
        $this->expectException(UnsupportedSheetsOperation::class);

        DB::connection('google-sheets')->transaction(static fn () => null);
    }
}

class Flight extends Model
{
    use SoftDeletes;

    protected $table = 'flights';

    protected $connection = 'google-sheets';

    protected $guarded = [];
}
