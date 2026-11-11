<?php

declare(strict_types=1);

/**
 * Seed a deployment shaped like the hosted service the day before the realm flip, into
 * the database DB_* points at (migrated, otherwise empty). For the rehearsal
 * (scripts/realm-flip-rehearsal.sh --seed); the same fixture the feature test flips.
 *
 *   php scripts/realm-flip/seed-fixture.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\RealmFlipFixture;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! class_exists(RealmFlipFixture::class)) {
    fwrite(STDERR, "The fixture lives under tests/: install the dev dependencies (composer install) first.\n");
    exit(1);
}

if (DB::table('environments')->exists()) {
    fwrite(STDERR, "This database already has environments. The fixture seeds an empty, migrated database only.\n");
    exit(1);
}

$built = RealmFlipFixture::build();

fwrite(STDOUT, json_encode($built, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
