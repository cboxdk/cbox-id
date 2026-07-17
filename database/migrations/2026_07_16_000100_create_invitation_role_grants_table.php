<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Access roles chosen for a person AT INVITE TIME. The invitee has no subject yet,
 * so the roles are parked here keyed by (organization, email) and applied when they
 * accept — no separate "assign roles" chore after they join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_role_grants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('environment_id')->index();
            $table->ulid('organization_id')->index();
            $table->string('email')->index();
            $table->ulid('role_id');
            $table->timestamps();

            $table->unique(['organization_id', 'email', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_role_grants');
    }
};
