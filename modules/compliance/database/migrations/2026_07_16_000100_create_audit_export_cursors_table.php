<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_export_cursors', function (Blueprint $table): void {
            $table->id();
            $table->string('scope')->unique();      // organization key, or '__system__'
            $table->string('organization_id', 26)->nullable();
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_export_cursors');
    }
};
