<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a retried request should get back: the first answer, not a second change.
 *
 * An agent or a backend that times out does not know whether its write landed, so it
 * retries — and without this, a retried "create an app" makes two apps with two secrets.
 * A machine caller sends `Idempotency-Key`; the first successful answer is kept here for a
 * day under (principal, key) and replayed for every retry that sends the same key and the
 * same request.
 *
 * `principal` is "kind:id" (`environment_key:01H…`), never a credential. `request_hash`
 * catches a key reused for a different request, which is refused rather than replayed.
 * Not environment-owned: the principal already pins the environment, and the sweep that
 * prunes this runs with no environment in context.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_idempotency_records', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('principal', 100);
            $table->string('idempotency_key', 255);
            $table->string('action', 100);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('status');
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('expires_at')->index();

            $table->unique(['principal', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_idempotency_records');
    }
};
