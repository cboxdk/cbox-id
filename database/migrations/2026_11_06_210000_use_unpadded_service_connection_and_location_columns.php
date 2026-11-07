<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Also repair databases which ran the original CHAR declarations. The
        // primary key and unique indexes survive change(); do not recreate them.
        Schema::table('service_connections', function (Blueprint $table): void {
            $table->string('id', 26)->change();
        });

        Schema::table('auth_sessions', function (Blueprint $table): void {
            $table->string('country_code', 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Keep the portable representation on rollback. Restoring CHAR would
        // reintroduce PostgreSQL padding without restoring any application data.
    }
};
