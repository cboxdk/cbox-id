<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AUDIT LOGS — the audit events an app built on an environment sends about its OWN
 * customers, kept per organization and shown to that organization's administrators.
 *
 * Not the platform's audit trail (`audit_entries`). That one records what happens to
 * identities here and is append-only for ever; these are somebody else's records, held on
 * their behalf, bounded by a retention the environment chooses and pruned to it. Mixing
 * the two would put a customer's `invoice.voided` beside our own `user.created` and make
 * neither prunable.
 *
 *  - `app_audit_events`: one row per event. Every organization's events are a HASH CHAIN
 *    of their own (`sequence`, `prev_hash`, `hash`), so a row changed, removed from the
 *    middle or reordered after it was written is detectable — within what retention keeps.
 *    Indexed for what is asked of it: an organization's events by time (the list, and its
 *    cursor), by action, by actor; the environment's by time (its list, and the prune);
 *    the chain's prefix by when it arrived (where the prune cuts).
 *  - `app_audit_event_targets`: each event's targets, one row each, so "everything that
 *    happened to invoice_123" is an index lookup and not a scan of JSON.
 *  - `app_audit_chains`: each organization's chain head — the row an append locks, so
 *    concurrent writers to one organization take turns and never share a sequence — and
 *    where the prune left the chain, so verification knows where it now starts.
 *  - `app_audit_schemas`: the optional shape an action's events must have.
 *  - `app_audit_settings`: an environment's retention and strict mode, when not the default.
 *  - `app_audit_exports`: CSV exports, generated on the queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_audit_events', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('environment_id', 26);
            $table->string('organization_id', 26);
            $table->unsignedBigInteger('sequence');
            $table->string('action', 190);
            // The schema version the event was checked against, or null when its action has none.
            $table->unsignedInteger('schema_version')->nullable();
            // As the sender said, to the millisecond. Not ours to trust: the chain orders by
            // `sequence`, and retention counts from `created_at`.
            $table->dateTime('occurred_at', 3);
            $table->string('actor_id', 190);
            $table->string('actor_type', 100);
            $table->string('actor_name', 190)->nullable();
            $table->json('actor_metadata')->nullable();
            $table->json('targets');
            $table->string('location', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('metadata')->nullable();
            $table->string('prev_hash', 64);
            $table->string('hash', 64);
            $table->dateTime('created_at');

            $table->unique(['environment_id', 'organization_id', 'sequence'], 'app_audit_events_chain_unique');
            $table->index(['environment_id', 'organization_id', 'occurred_at', 'id'], 'app_audit_events_org_time_index');
            $table->index(['environment_id', 'action', 'occurred_at'], 'app_audit_events_action_index');
            $table->index(['environment_id', 'actor_id'], 'app_audit_events_actor_index');
            $table->index(['environment_id', 'occurred_at', 'id'], 'app_audit_events_env_time_index');
            $table->index(['environment_id', 'organization_id', 'created_at'], 'app_audit_events_received_index');
        });

        Schema::create('app_audit_event_targets', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id', 26)->index();
            $table->string('environment_id', 26);
            $table->string('organization_id', 26);
            $table->string('type', 100);
            $table->string('target_id', 190);

            $table->index(['environment_id', 'target_id'], 'app_audit_event_targets_target_index');
        });

        Schema::create('app_audit_chains', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('environment_id', 26);
            $table->string('organization_id', 26);
            $table->unsignedBigInteger('head_sequence')->default(0);
            $table->string('head_hash', 64);
            // The last sequence the prune removed, and that event's hash: the link the oldest
            // event still kept must point back to.
            $table->unsignedBigInteger('pruned_through_sequence')->default(0);
            $table->string('pruned_through_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['environment_id', 'organization_id'], 'app_audit_chains_org_unique');
        });

        Schema::create('app_audit_schemas', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('environment_id', 26);
            $table->string('action', 190);
            $table->unsignedInteger('version')->default(1);
            $table->json('actor_metadata')->nullable();
            $table->json('targets')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['environment_id', 'action'], 'app_audit_schemas_action_unique');
        });

        Schema::create('app_audit_settings', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('environment_id', 26)->unique();
            $table->unsignedInteger('retention_days');
            $table->boolean('strict_schemas')->default(false);
            $table->timestamps();
        });

        Schema::create('app_audit_exports', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('environment_id', 26)->index();
            $table->string('organization_id', 26)->nullable()->index();
            $table->json('filters');
            $table->string('state', 20);
            $table->unsignedInteger('row_count')->nullable();
            $table->string('path')->nullable();
            $table->string('error')->nullable();
            // Who asked: the person's or the key's id, as the trail names them.
            $table->string('requested_by', 190);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_audit_exports');
        Schema::dropIfExists('app_audit_settings');
        Schema::dropIfExists('app_audit_schemas');
        Schema::dropIfExists('app_audit_chains');
        Schema::dropIfExists('app_audit_event_targets');
        Schema::dropIfExists('app_audit_events');
    }
};
