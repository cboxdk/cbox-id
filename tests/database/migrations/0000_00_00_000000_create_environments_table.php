<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A minimal `environments` table for the test suite only — it mirrors the schema
 * laravel-id ships (see cboxdk/laravel-id's create_environments_table) so the real
 * Environment model and DatabaseEnvironmentResolver can round-trip a custom domain
 * without booting the whole framework. Not shipped by the package.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('domain')->nullable()->unique();
            $table->string('status')->default('active');
            $table->boolean('is_default')->default(false)->index();
            $table->json('settings')->default('{}');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environments');
    }
};
