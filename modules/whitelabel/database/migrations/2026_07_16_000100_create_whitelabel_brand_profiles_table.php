<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whitelabel_brand_profiles', function (Blueprint $table): void {
            $table->id();
            // The hard environment boundary this profile belongs to (BelongsToEnvironment).
            $table->ulid('environment_id')->index();
            // Null = the environment-wide default brand; set = an org-specific override.
            $table->ulid('organization_id')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('favicon_url')->nullable();
            // Validated `token => colour` map (hex|oklch), mapped to CSS custom properties.
            $table->json('palette');
            $table->string('app_name')->nullable();
            $table->string('email_from_name')->nullable();
            $table->json('email_templates');
            $table->timestamps();

            // One profile per (environment, organization). Multiple NULL organization
            // rows are possible at the DB level; the environment default is enforced in
            // the application layer (there is only ever one per environment).
            $table->unique(['environment_id', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whitelabel_brand_profiles');
    }
};
