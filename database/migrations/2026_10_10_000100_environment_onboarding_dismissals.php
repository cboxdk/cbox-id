<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

    /**
     * Back to "every dismissal names an organization". The environment checklist's
     * dismissals have no place in that schema and are removed: the cost is that the
     * checklist shows again for whoever had put it away, and the release this rolls back to
     * has no such checklist to show. Leaving the column nullable instead would hand that
     * release a schema its own migrations never produced.
     */
    public function down(): void
    {
        DB::table('onboarding_dismissals')->whereNull('organization_id')->delete();

        Schema::table('onboarding_dismissals', function (Blueprint $table): void {
            $table->dropIndex(['environment_id', 'subject_id']);
            $table->string('organization_id', 26)->nullable(false)->change();
        });
    }
};
