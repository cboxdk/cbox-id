<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The table the shipped configuration has always pointed at, and nothing created.
 *
 * `config/session.php` defaults the driver to `database` and `.env.example` states it
 * outright — and no migration in this application, in `cboxdk/laravel-id`, or in any module
 * ever created `sessions`. A stranger who followed the documented install
 * (`cp .env.example .env` → `migrate`) got a 500 on the FIRST request, including on
 * `/first-run`, the one page that exists for a container somebody else started.
 *
 * It survived because every environment that runs this diverges from the shipped example
 * in exactly this place: `tests/bootstrap.php` pins `SESSION_DRIVER=array`, developer `.env`
 * files say `file`, and the hosted deployment sets it explicitly. The suite, the dev box
 * and production were each fine, and the thing none of them exercised was the file a new
 * customer actually copies.
 *
 * The table is Laravel's own, verbatim, rather than a variation: `database` is the right
 * default for a product that will be run on more than one instance — `file` sessions do
 * not survive a second container — so the fix is to make the shipped default TRUE rather
 * than to quietly downgrade it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `hasTable` because a deployment may already have run Laravel's stock migration
        // — this application shipped without one for long enough that an operator who hit
        // the 500 may well have added it by hand, and a migration that fails on their
        // install is a worse answer than one that notices.
        if (Schema::hasTable('sessions')) {
            return;
        }

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
