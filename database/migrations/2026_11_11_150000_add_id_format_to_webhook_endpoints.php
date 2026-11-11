<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which form of id an endpoint's deliveries carry (plan §3.2): `raw`, today's bare ULIDs, or
 * `prefixed`, public ids (`usr_01j…`). Every endpoint that exists keeps `raw` — every Cbox
 * app's mirror joins on the bare id — and an endpoint moves when its receiver reads both.
 * Additive and defaulted, so the running version keeps writing rows without knowing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            $table->string('id_format', 16)->default('raw');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            $table->dropColumn('id_format');
        });
    }
};
