<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A CLIENT ID IS NOT A ULID, and `legacy_login_declarations.client_id` was declared as
 * though it were.
 *
 * The framework's migration spells it `string('client_id', 26)` — copied from the ULID
 * columns beside it, which are genuinely 26 characters. A client id is not one of those:
 * `ClientRegistryService` mints `'cid_'.Str::ulid()`, so every id this column will ever
 * hold is THIRTY characters, four longer than the column can take.
 *
 * What that costs, per engine:
 *
 *  - PostgreSQL refuses the insert outright — `SQLSTATE[22001] value too long for type
 *    character varying(26)`. Declaring a legacy login is impossible.
 *  - MySQL in strict mode does the same; without it, the id is silently TRUNCATED to
 *    `cid_01m0zcf84c14s7ng7tzhj7h`, which matches no client, so the console can never
 *    name the app that proposed the URL.
 *  - SQLite ignores declared widths entirely, which is why every test passed and why
 *    nothing said anything for as long as the suite ran on sqlite alone.
 *
 * Widened rather than worked around, and widened HERE rather than waited for: this repo
 * already carries migrations that alter the framework's tables, the feature is unusable
 * on both engines anybody deploys on, and a fresh install at 255 is what the column's
 * eleven siblings in the same package already are. The framework's own migration wants
 * the same fix — see the note in the release checklist — and running both is harmless,
 * because this states a width rather than a delta.
 *
 * NO BACKFILL, and none is possible: on the engines where the column is wrong no row was
 * ever written, and on sqlite the value was stored whole regardless of the declaration.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The table belongs to the framework and arrives with it. A deployment composing a
        // version that predates the feature has nothing to widen.
        if (! Schema::hasTable('legacy_login_declarations')) {
            return;
        }

        Schema::table('legacy_login_declarations', function (Blueprint $table): void {
            // `string()` with no length, exactly as every other `client_id` column in the
            // package is declared — 255, which is the width a client id should have been
            // sharing all along. Stating a number here would be one more place for the
            // shape of an id to be written down and drift.
            //
            // NO `->index()`. The index is already there from the create, and `change()`
            // treats a modifier as something to APPLY rather than to preserve — so
            // spelling it here asks Postgres to create an index it already has, and the
            // migration dies on a duplicate relation instead of widening anything. The
            // existing index survives a type change on its own.
            $table->string('client_id')->change();
        });
    }

    public function down(): void
    {
        // NOT REVERSED. Narrowing it back would refuse or truncate every row written since
        // this ran, and the previous width could not hold a single valid value — there is
        // no state worth returning to.
    }
};
