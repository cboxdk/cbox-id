<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_connection_transitions', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 26);
            $table->string('connection_id', 26);
            $table->json('payload');
            // This bounded migration runs once per connection, even with a new manifest.
            // No foreign-key cascades: evidence outlives clients, connections and approvals.
            $table->unique(['environment_id', 'connection_id'], 'service_connection_transition_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_connection_transitions');
    }
};
