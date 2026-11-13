<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a person's browser asked for a phone approval — written when the request is filed,
 * read by the push and the approvals API. Additive and nullable: a request filed by a
 * server (CIBA, a key's held action) has no "where" and leaves them empty. Never the IP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ciba_requests', function (Blueprint $table): void {
            $table->string('origin_kind', 20)->nullable();
            $table->string('origin_app', 160)->nullable();
            $table->string('origin_country_code', 2)->nullable();
            $table->string('origin_city', 160)->nullable();
            $table->string('origin_region', 160)->nullable();
            $table->string('origin_user_agent', 512)->nullable();
            $table->string('origin_host', 253)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ciba_requests', function (Blueprint $table): void {
            $table->dropColumn(['origin_kind', 'origin_app', 'origin_country_code', 'origin_city', 'origin_region', 'origin_user_agent', 'origin_host']);
        });
    }
};
