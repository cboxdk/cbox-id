<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('id_device_approval_evidence', function (Blueprint $table): void {
            $table->string('request_id', 26)->primary();
            $table->string('environment_id', 26)->index();
            $table->string('device_id', 26);
            $table->foreign('request_id')->references('id')->on('ciba_requests')->cascadeOnDelete();
            $table->foreign('device_id')->references('id')->on('id_devices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('id_device_approval_evidence');
    }
};
