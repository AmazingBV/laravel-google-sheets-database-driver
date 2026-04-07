<?php

declare(strict_types=1);

namespace AmazingBV\GoogleSheetsDatabaseDriver\Tests\Feature;

use AmazingBV\GoogleSheetsDatabaseDriver\Tests\TestCase;
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

    public function test_transactions_are_ignored_as_no_op_control_flow(): void
    {
        $connection = DB::connection('google-sheets');
        $called = false;

        $result = $connection->transaction(function () use (&$called) {
            $called = true;

            return 'ok';
        });

        $this->assertTrue($called);
        $this->assertSame('ok', $result);
        $this->assertSame(0, $connection->transactionLevel());
    }

    public function test_transaction_rolls_back_its_no_op_level_when_callback_throws(): void
    {
        $connection = DB::connection('google-sheets');

        try {
            $connection->transaction(static function (): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertSame(0, $connection->transactionLevel());
    }
}

class Flight extends Model
{
    use SoftDeletes;

    protected $table = 'flights';

    protected $connection = 'google-sheets';

    protected $guarded = [];
}
