<?php

declare(strict_types=1);

use Cbox\Id\Whitelabel\Assets\DatabaseBrandAssetStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uploaded logos and favicons, in the database every replica shares.
 *
 * See {@see DatabaseBrandAssetStore} for why these are not files. The contents are base64
 * in a long text column rather than a binary one: a logo is at most 1 MB (the form says
 * so), and text is the one column type that holds that on every engine this application
 * runs on without a per-driver blob size or a PDO stream to manage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whitelabel_brand_assets', function (Blueprint $table): void {
            // `brand/{environment}/{kind}-{random}.{ext}` — the path the public URL names,
            // unguessable by its random part, and the key it is served by.
            $table->string('path', 191)->primary();
            // Whose asset this is, so a replacement can only ever remove its own.
            $table->string('environment_key', 64)->index();
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->longText('contents');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whitelabel_brand_assets');
    }
};
