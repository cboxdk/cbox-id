<?php

declare(strict_types=1);

use App\Platform\StandingApprovals\StandingApprovalTokenExchange;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STANDING APPROVALS — an organization owner's standing "yes" to one machine client's
 * deployments to one environment of an app (Cbox Infra's ADR-0017 Addendum A).
 *
 * While a row is active, the token exchange issues the app (`app_client_id`) a plan-bound
 * approval token for that client's deployment to that environment
 * ({@see StandingApprovalTokenExchange}). The environment is
 * the APP's (`environment_ref`, `environment_kind`), not one of ours, hence the names.
 * Revoked rows stay, for the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standing_approvals', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('environment_id', 26)->index();
            $table->string('organization_id', 26);
            // The machine client whose deployments it approves (client credentials).
            $table->string('client_id', 191);
            // The app the approval token is issued to: the one that exchanges for it.
            $table->string('app_client_id', 191);
            // The app's environment and its kind (staging, development, …; never production).
            $table->string('environment_ref', 64);
            $table->string('environment_kind', 32);
            // The owner who granted it (a subject id), and when.
            $table->string('granted_by', 64);
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_by', 64)->nullable();
            $table->string('revoke_reason', 64)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'client_id', 'environment_ref']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standing_approvals');
    }
};
