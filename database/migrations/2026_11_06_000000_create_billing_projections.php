<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('id_billing_plans', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->json('data');
            $table->boolean('sold')->default(true);
            $table->timestamps();
        });
        Schema::create('id_billing_subscriptions', function (Blueprint $table): void {
            $table->string('project_id', 64)->primary();
            $table->string('organization_id', 64)->index();
            $table->string('plan');
            $table->string('status', 32);
            $table->json('data');
            $table->timestamp('remote_updated_at')->nullable();
            $table->timestamps();
        });
        Schema::create('id_billing_sync', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->timestamp('watermark')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('id_billing_sync');
        Schema::dropIfExists('id_billing_subscriptions');
        Schema::dropIfExists('id_billing_plans');
    }
};
