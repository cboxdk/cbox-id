<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The record `cbox-id:realm:flip` keeps of the one-time move from two realms to one.
 *
 * `realm_flips` is one row per executed flip: which environment was the platform root,
 * which one became it, what was planned and what was done. It is how a second
 * `--execute` knows there is nothing left to do and how `--verify` knows which ids must
 * no longer appear anywhere, after the rows that named them are gone.
 *
 * `realm_flip_ids` is the map: every id of the old root (the environment, its
 * organizations, users and memberships) and the id that stands for it now, or none when
 * it was dropped. Billing and the other systems that key on an organization id follow
 * this table.
 *
 * `realm_flip_archive` holds the old root's tamper-evident audit trail verbatim. An entry's
 * hash covers its environment id, scope and sequence, so the trail cannot be carried into
 * another environment's chain without rewriting it; it is kept here, unchanged, instead.
 *
 * Additive: nothing reads these tables but the command.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('realm_flips', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('retired_environment', 26)->unique();
            $table->string('realm_environment', 26);
            $table->text('summary');
            $table->timestamp('executed_at');
            $table->timestamps();
        });

        Schema::create('realm_flip_ids', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16);
            $table->string('old_id', 64);
            $table->string('new_id', 64)->nullable();
            $table->boolean('retired');
            $table->string('note', 191)->nullable();

            $table->unique(['kind', 'old_id']);
            $table->index('old_id');
        });

        Schema::create('realm_flip_archive', function (Blueprint $table): void {
            $table->id();
            $table->string('source_table', 64);
            $table->string('source_key', 64)->nullable();
            $table->string('chain', 191)->nullable();
            $table->unsignedBigInteger('sequence')->nullable();
            $table->text('payload');
            $table->timestamp('archived_at');

            $table->index(['source_table', 'chain', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realm_flip_archive');
        Schema::dropIfExists('realm_flip_ids');
        Schema::dropIfExists('realm_flips');
    }
};
