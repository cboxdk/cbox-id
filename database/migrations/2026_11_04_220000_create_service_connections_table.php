<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_connections', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('environment_id', 128);
            $table->string('organization_id', 128);
            $table->string('consumer_client_id', 128);
            $table->string('service', 64);
            $table->string('service_client_id');
            $table->string('audience');
            $table->json('scopes');
            $table->string('created_by');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['environment_id', 'organization_id', 'consumer_client_id', 'service'], 'service_connections_owner_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_connections');
    }
};
