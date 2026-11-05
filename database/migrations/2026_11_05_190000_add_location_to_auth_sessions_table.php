<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auth_sessions', function (Blueprint $table): void {
            $table->string('country_code', 2)->nullable();
            $table->string('city', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('auth_sessions', function (Blueprint $table): void {
            $table->dropColumn(['country_code', 'city']);
        });
    }
};
