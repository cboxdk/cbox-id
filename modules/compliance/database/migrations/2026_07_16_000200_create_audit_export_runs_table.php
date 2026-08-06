<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_export_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
            $table->unsignedInteger('scopes_scanned')->default(0);
            $table->unsignedBigInteger('entries_exported')->default(0);
            $table->unsignedInteger('batches')->default(0);
            $table->string('sink')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_export_runs');
    }
};
