<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin Portal setup links — the app-owned table backing the WorkOS-style
 * "invite your IT admin to set up SSO/SCIM" flow. Only the token's SHA-256 hash
 * is stored; the plaintext is shown once at generation and never persisted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_portal_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            // 'sso' | 'scim' | 'both' — which surfaces the redeemer may configure.
            $table->string('scope');
            // SHA-256 hex of the random token; the plaintext is never stored.
            $table->string('token_hash')->index();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            // The platform subject id that minted the link.
            $table->string('created_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_portal_links');
    }
};
