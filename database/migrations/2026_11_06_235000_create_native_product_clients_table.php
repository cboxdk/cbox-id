<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('native_product_clients', function (Blueprint $table): void {
            $table->string('product', 32)->primary();
            $table->string('environment_id', 26);
            $table->string('client_id', 100)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('native_product_clients');
    }
};
