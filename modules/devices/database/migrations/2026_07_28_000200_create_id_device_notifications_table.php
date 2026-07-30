<?php

declare(strict_types=1);

use Cbox\Id\Kernel\Database\JsonDefault;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One attempted push to one device. Written BEFORE the queue job is dispatched, so a
 * queue that drops the message still leaves the delivery visible to the retry sweep —
 * the row is the source of truth, the job is only an optimisation that makes it prompt.
 *
 * `sequence` is gap-free per device, allocated under a row lock. It exists so the
 * console can say "notification 41 of 43 was the one that failed" without inferring
 * order from timestamps, and so a duplicate job is detectable rather than merely
 * improbable.
 *
 * `expires_at` is what makes this table different from webhook deliveries. An approval
 * prompt has a deadline (the CIBA request's own TTL, 300s by default) and a prompt
 * that lands after it is WORSE than no prompt at all: the user taps Approve and gets
 * an error they cannot act on. Delivery settles as Expired rather than retrying.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('id_device_notifications', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('environment_id', 26)->index();
            $table->string('device_id', 26);

            $table->unsignedBigInteger('sequence');
            $table->string('kind', 32);
            $table->string('status', 16);

            // No literal default on a json column: MySQL rejects it outright (errno
            // 1101) and has never allowed one. JsonDefault emits the expression form
            // MySQL takes while keeping the DDL byte-identical on Postgres and SQLite.
            $table->json('payload')->default(JsonDefault::emptyObject());

            $table->unsignedInteger('attempt')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('last_error', 255)->nullable();

            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->unique(['device_id', 'sequence'], 'id_device_notifications_device_seq_uq');
            // The retry sweep: due Failed rows, then Pending rows old enough to count
            // as stranded. Both are (status, timestamp) range scans.
            $table->index(['status', 'next_retry_at'], 'id_device_notifications_status_retry_idx');
            $table->index(['status', 'created_at'], 'id_device_notifications_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('id_device_notifications');
    }
};
