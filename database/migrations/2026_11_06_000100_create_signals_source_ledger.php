<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signals_source_heads', function (Blueprint $table): void {
            $table->string('environment_id', 190)->primary();
            $table->string('generation', 36);
            $table->unsignedBigInteger('sequence')->default(0);
            $table->unsignedBigInteger('retained_after')->default(0);
            $table->unsignedBigInteger('record_count')->default(0);
            $table->timestampTz('started_at', 6);
            $table->timestampTz('heartbeat_at', 6)->nullable();
        });
        Schema::create('signals_source_erasure_positions', function (Blueprint $table): void {
            $table->string('environment_id', 190);
            $table->unsignedBigInteger('sequence');
            $table->string('id', 190);
            $table->json('record');
            $table->primary(['environment_id', 'sequence']);
            $table->unique(['environment_id', 'id']);
        });
        Schema::create('signals_source_memberships', function (Blueprint $table): void {
            $table->string('environment_id', 190);
            $table->string('membership_id', 190);
            $table->string('subject_hash', 64);
            $table->string('assertion_id', 190);
            $table->timestampTz('valid_from', 6);
            $table->timestampTz('valid_until', 6)->nullable();
            $table->primary(['environment_id', 'membership_id']);
        });
        Schema::create('signals_source_pending', function (Blueprint $table): void {
            $table->string('environment_id', 190);
            $table->string('id', 190);
            $table->string('name', 100);
            $table->string('organization_id', 190)->nullable();
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('recorded_at', 6);
            $table->json('data');
            $table->json('subject_hashes');
            $table->primary(['environment_id', 'id']);
            $table->index(['environment_id', 'recorded_at']);
        });
        Schema::create('signals_source_records', function (Blueprint $table): void {
            $table->string('environment_id', 190);
            $table->unsignedBigInteger('sequence');
            $table->string('id', 190);
            $table->string('name', 100);
            $table->string('organization_id', 190)->nullable();
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('recorded_at', 6);
            $table->json('data');
            $table->primary(['environment_id', 'sequence']);
            $table->unique(['environment_id', 'id']);
            $table->index(['environment_id', 'recorded_at']);
        });
        Schema::create('signals_source_subjects', function (Blueprint $table): void {
            $table->string('environment_id', 190);
            $table->unsignedBigInteger('sequence');
            $table->string('subject_hash', 64);
            $table->primary(['environment_id', 'sequence', 'subject_hash'], 'signals_source_subjects_pk');
            $table->index(['environment_id', 'subject_hash']);
        });
        Schema::create('signals_source_erasures', function (Blueprint $table): void {
            $table->string('environment_id', 190);
            $table->string('subject_hash', 64);
            $table->timestampTz('erased_at', 6);
            $table->primary(['environment_id', 'subject_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signals_source_erasure_positions');
        Schema::dropIfExists('signals_source_memberships');
        Schema::dropIfExists('signals_source_pending');
        Schema::dropIfExists('signals_source_erasures');
        Schema::dropIfExists('signals_source_subjects');
        Schema::dropIfExists('signals_source_records');
        Schema::dropIfExists('signals_source_heads');
    }
};
