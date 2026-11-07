<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signals_inbox_receipts', function (Blueprint $table): void {
            $table->string('project', 190);
            $table->string('environment', 190);
            $table->string('delivery_id', 190);
            $table->char('body_hash', 64);
            $table->timestampTz('received_at');
            $table->primary(['project', 'environment', 'delivery_id'], 'signals_inbox_receipt_key');
        });
        Schema::create('signals_inbox_state', function (Blueprint $table): void {
            $table->string('project', 190);
            $table->string('environment', 190);
            $table->string('signal_id', 190);
            $table->unsignedBigInteger('revision');
            $table->string('type');
            $table->timestampTz('expires_at');
            $table->text('body');
            $table->timestampTz('updated_at');
            $table->primary(['project', 'environment', 'signal_id'], 'signals_inbox_state_key');
        });
        Schema::create('signals_event_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('body_hash', 64);
            $table->text('body');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at');
            $table->timestampTz('created_at');
            $table->timestampTz('sent_at')->nullable();
            $table->string('error_code')->nullable();
            $table->string('operation_id')->nullable();
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signals_event_outbox');
        Schema::dropIfExists('signals_inbox_state');
        Schema::dropIfExists('signals_inbox_receipts');
    }
};
