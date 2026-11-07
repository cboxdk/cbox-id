<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_billing_usage_outbox', function (Blueprint $table): void {
            $table->string('event_id', 128)->primary();
            $table->string('workspace_id', 128)->index();
            $table->string('meter');
            $table->timestampTz('occurred_at');
            $table->timestampTz('reported_at')->nullable()->index();
            $table->text('last_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_billing_usage_outbox');
    }
};
