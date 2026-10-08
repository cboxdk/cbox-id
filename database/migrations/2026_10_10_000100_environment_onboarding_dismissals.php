<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The environment console's "Get started" can be put away too — per person, per
 * environment. Such a dismissal belongs to no organization, so the column that named one
 * becomes optional: a row with no organization is the environment's own checklist,
 * dismissed by `subject_id` in `environment_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_dismissals', function (Blueprint $table): void {
            $table->string('organization_id', 26)->nullable()->change();
            $table->index(['environment_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_dismissals', function (Blueprint $table): void {
            $table->dropIndex(['environment_id', 'subject_id']);
        });
    }
};
