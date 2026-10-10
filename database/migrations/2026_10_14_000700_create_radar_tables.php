<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RADAR — the environment's decisions about sign-in and sign-up protection, and the little
 * it has to remember to make them.
 *
 *  - `radar_settings`: one row per environment that chose anything — its mode (monitor or
 *    enforce; null inherits the deployment's `RISK_MODE`) and its tuning of the built-in
 *    rules. No row means every default.
 *  - `radar_rules`: the environment's own rules, evaluated in `position` order, first match
 *    wins. Conditions are structured JSON (field, operator, value) — never code.
 *  - `radar_list_entries`: the allow and deny lists — an IP or CIDR, an address, a mail
 *    domain, a device. Values an ADMINISTRATOR typed, kept as typed so they can be read
 *    back; they are configuration, not telemetry.
 *  - `radar_devices`: the browsers each account has SUCCESSFULLY signed in from, keyed by
 *    pseudonyms only (the account, the device cookie, the user-agent fingerprint), with the
 *    coarse location of the last sign-in on each (country, and latitude/longitude rounded
 *    to one decimal — about 11 km). That is what "new device" and "impossible travel" are
 *    measured against. Bounded by `cbox-id.radar.device_retention_days`.
 *
 * And `risk_decisions` grows the verdict: what Radar decided, whether it was enforced, the
 * rule that decided it, the facts it decided on — still without the IP or the address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radar_settings', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('environment_id', 26)->unique();
            $table->string('mode', 16)->nullable();
            $table->json('builtin_rules')->nullable();
            $table->timestamps();
        });

        Schema::create('radar_rules', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('environment_id', 26);
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('position');
            $table->boolean('enabled')->default(true);
            // all | sign_in | sign_up
            $table->string('applies_to', 16);
            // allow | challenge | block
            $table->string('action', 16);
            $table->json('conditions');
            $table->timestamps();

            $table->index(['environment_id', 'position']);
        });

        Schema::create('radar_list_entries', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('environment_id', 26);
            // allow | deny
            $table->string('list', 8);
            // ip | email | email_domain | device
            $table->string('kind', 16);
            $table->string('value', 255);
            $table->string('note', 255)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['environment_id', 'list', 'kind', 'value']);
        });

        Schema::create('radar_devices', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('environment_id', 26);
            // Keyed pseudonyms (HMAC under app.key), never the raw values.
            $table->string('subject_hash', 64);
            $table->string('device_hash', 64)->nullable();
            $table->string('fingerprint_hash', 64);
            // Coarse: a country, and a point rounded to one decimal (~11 km).
            $table->string('country', 2)->nullable();
            $table->decimal('latitude', 5, 1)->nullable();
            $table->decimal('longitude', 5, 1)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            $table->index(['environment_id', 'subject_hash']);
            $table->index('last_seen_at');
        });

        Schema::table('risk_decisions', function (Blueprint $table): void {
            // allow | challenge | block — what Radar decided, whatever the mode.
            $table->string('verdict', 16)->nullable();
            // Whether the verdict was acted on (enforce) or only recorded (monitor).
            $table->boolean('enforced')->default(false);
            // What decided it: `deny_list:ip`, `rule:<id>`, `builtin:credential_stuffing`…
            $table->string('rule', 64)->nullable();
            // password | magic_link | passkey | sign_up
            $table->string('method', 16)->nullable();
            $table->string('country', 2)->nullable();
            $table->unsignedInteger('asn')->nullable();
            $table->string('device_hash', 64)->nullable();
            // Every rule that matched, and the facts they were evaluated on.
            $table->json('triggered')->nullable();
            $table->json('facts')->nullable();

            // The explorer: one environment's decisions, newest first, by verdict.
            $table->index(['environment_id', 'assessed_at']);
            $table->index(['environment_id', 'verdict', 'assessed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('risk_decisions', function (Blueprint $table): void {
            $table->dropIndex(['environment_id', 'assessed_at']);
            $table->dropIndex(['environment_id', 'verdict', 'assessed_at']);
            $table->dropColumn(['verdict', 'enforced', 'rule', 'method', 'country', 'asn', 'device_hash', 'triggered', 'facts']);
        });

        Schema::dropIfExists('radar_devices');
        Schema::dropIfExists('radar_list_entries');
        Schema::dropIfExists('radar_rules');
        Schema::dropIfExists('radar_settings');
    }
};
