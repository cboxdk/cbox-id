<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('id_approval_reports', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('environment_id', 26)->index();
            $table->string('subject_id', 26)->index();
            $table->string('request_id', 26);
            $table->timestamps();
            $table->unique(['environment_id', 'request_id'], 'id_approval_report_request_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('id_approval_reports');
    }
};
