<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A catalog app carries a second icon for dark surfaces. Additive and nullable: the
 * running version keeps reading and writing `icon_data` and never sees this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_catalog_entries', function (Blueprint $table): void {
            $table->mediumText('icon_dark_data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('app_catalog_entries', function (Blueprint $table): void {
            $table->dropColumn('icon_dark_data');
        });
    }
};
