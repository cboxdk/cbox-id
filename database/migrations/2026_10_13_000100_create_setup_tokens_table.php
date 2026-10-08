<?php

declare(strict_types=1);

use App\Platform\Install\DatabaseSetupTokens;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the first-run setup token lives — in the database every replica shares, not on
 * one replica's disk ({@see DatabaseSetupTokens}).
 *
 * One row per purpose, keyed by it, so arming is a single insert that a second replica
 * racing the first cannot duplicate. Only a SHA-256 of the token is stored: a database
 * dump or a read replica is not a way to claim an unclaimed deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setup_tokens', function (Blueprint $table): void {
            $table->string('purpose', 32)->primary();
            $table->string('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setup_tokens');
    }
};
