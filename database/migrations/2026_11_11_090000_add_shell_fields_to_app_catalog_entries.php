<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the app switcher shows beside an app's name: a one-line purpose, a cbox-ui hue,
 * and for an app the person does not have yet, where they ask for it. Additive: every
 * column is nullable or defaulted, so the running version keeps reading and writing rows
 * without knowing them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_catalog_entries', function (Blueprint $table): void {
            $table->string('purpose', 60)->nullable();
            $table->string('hue', 20)->nullable();
            $table->string('admission_url', 2048)->nullable();
            $table->boolean('promote_when_unavailable')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('app_catalog_entries', function (Blueprint $table): void {
            $table->dropColumn(['purpose', 'hue', 'admission_url', 'promote_when_unavailable']);
        });
    }
};
