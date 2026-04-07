<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('google-sheets')->create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->integer('price');
        });
    }

    public function down(): void
    {
        Schema::connection('google-sheets')->dropIfExists('products');
    }
};
