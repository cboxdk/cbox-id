<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who asked for each action approval. The approval itself is the framework's CIBA request
 * (in the platform root, where the approving person's devices are); this row binds it to the
 * credential that raised it, so a different credential can neither poll nor spend it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_approval_requests', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('principal', 100)->index();
            $table->string('action', 100);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_approval_requests');
    }
};
