<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An admin having dismissed the setup checklist for an organization. Whether each
 * step is DONE is measured from live state and never stored — this table holds only
 * the one thing that cannot be derived: "I have seen this, put it away."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_dismissals', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('environment_id', 26)->index();
            $table->string('organization_id', 26)->index();
            $table->string('subject_id', 26);
            $table->timestamps();

            $table->unique(['organization_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_dismissals');
    }
};
