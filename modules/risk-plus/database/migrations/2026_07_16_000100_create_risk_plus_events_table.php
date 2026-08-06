<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_plus_events', function (Blueprint $table): void {
            $table->id();
            $table->string('action');
            $table->string('outcome');
            $table->float('score');
            $table->string('ip')->nullable();
            $table->string('email')->nullable();
            $table->json('reasons');
            $table->timestamp('created_at')->nullable();

            $table->index(['created_at', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_plus_events');
    }
};
