<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_catalog_entries', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('environment_id', 64)->index();
            $table->string('key', 64);
            $table->string('name', 120);
            $table->string('description', 300)->nullable();
            $table->string('launch_url', 2048);
            $table->mediumText('icon_data')->nullable();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->string('audience', 20);
            $table->json('organization_ids');
            $table->json('user_ids');
            $table->json('client_ids');
            $table->string('required_feature', 64)->nullable();
            $table->unsignedBigInteger('version');
            $table->timestamps();
            $table->unique(['environment_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_catalog_entries');
    }
};
