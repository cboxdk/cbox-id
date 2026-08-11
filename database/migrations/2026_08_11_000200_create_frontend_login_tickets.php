<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The thing an embedded sign-in form gets INSTEAD of a token.
 *
 * A page that renders its own credential form has proved somebody's password, and the
 * obvious next step — hand it tokens — is the implicit grant, which OAuth 2.1 removes for
 * good reasons: tokens in a URL, in history, in `Referer`, with no client authentication
 * and no PKCE binding.
 *
 * So the form gets a ticket and nothing else. The page then walks the ordinary
 * `/oauth/authorize` flow with its PKCE challenge, presenting the ticket instead of an
 * existing session cookie. `/authorize` stays the only thing in the product that issues an
 * authorization code, and everything downstream of it is unchanged. This is the shape
 * Auth0 calls cross-origin authentication, and it exists for exactly this reason.
 *
 * HASHED AT REST, unlike a publishable key. The key is public by design; this is a bearer
 * credential that stands in for a completed password check, so a database leak must not
 * hand somebody a working sign-in.
 *
 * BOUND TO THE KEY THAT MINTED IT. A ticket minted for one customer's page cannot be
 * redeemed by another's, even though both hold valid keys against the same deployment —
 * the environment alone is not a tight enough boundary when several origins share one.
 *
 * SIXTY SECONDS, single use. It exists to cross one redirect. Anything longer is a window
 * for a ticket lifted from a URL, and a URL is exactly where it will be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('frontend_login_tickets', function (Blueprint $table): void {
            // `string(…, 26)`, never `ulid()`: PostgreSQL implements CHAR as blank-padded
            // `bpchar`, so a value comes back padded and a strict comparison fails.
            $table->string('id', 26)->primary();
            $table->string('environment_id', 26)->index();

            // The lookup, and the only copy we keep. sha256 rather than a password hash:
            // the value has full entropy and is checked on a hot path, so a slow KDF would
            // buy nothing against an attacker who cannot guess it in the first place.
            $table->string('token_hash', 64)->unique();

            $table->string('publishable_key_id', 26);
            $table->string('subject_id', 26)->index();

            // What the credential check actually established, carried through to the
            // session the ticket creates — so an embedded sign-in produces the same `amr`
            // as the hosted one, and `acr` cannot disagree between the two doors.
            $table->json('amr');

            $table->timestamp('expires_at')->index();

            // Set on redemption rather than deleted, so a replay is distinguishable from a
            // ticket that never existed — the first is an attack worth seeing in an audit
            // trail, the second is a typo.
            $table->timestamp('redeemed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('frontend_login_tickets');
    }
};
