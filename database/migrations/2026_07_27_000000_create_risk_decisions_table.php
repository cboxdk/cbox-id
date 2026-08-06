<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The durable risk trail — one row per risk assessment, so a threshold can be set
 * from evidence instead of a guess.
 *
 * Every decision used to go to `Log::info` only. Production runs `LOG_CHANNEL=stderr`
 * with no aggregation, so the entire monitor-mode corpus was destroyed on each pod
 * rollout: weeks of scoring produced nothing anyone could query, and `risk.mode` could
 * not be moved off `monitor` responsibly.
 *
 * This is deliberately NOT the audit chain (`audit_logs`). That table takes a
 * `SELECT ... FOR UPDATE` on its per-(environment, scope) head inside a transaction on
 * every append, and these writes happen BEFORE authentication — an unauthenticated
 * client could serialise the tamper-evident chain at will. See
 * `docs/security/adaptive-risk.md` for the measurements behind that call.
 *
 * NO plaintext IP and NO plaintext email address. Both are keyed HMACs under
 * `app.key`, which keeps the pre-auth trail from becoming the platform's largest
 * store of personal data while staying exactly correlatable for an operator who
 * already knows the address they are investigating. The email DOMAIN is kept in the
 * clear — it is a property of a mail provider, not of a person, and disposable-domain
 * and provider-abuse patterns are unreadable without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_decisions', function (Blueprint $table): void {
            $table->string('id', 26)->primary();

            // The environment whose host the attempt landed on. A PLAIN column, not the
            // `BelongsToEnvironment` scope: tuning a threshold is a deployment-wide
            // question asked from a console with no environment in context, where the
            // hard outer scope emits `1 = 0` and would silently answer "no traffic".
            // Same posture as the maintenance sweep and the outbox relay.
            $table->string('environment_id')->nullable();

            // What was being attempted — 'login' or 'register' today. Thresholds are
            // tuned per action, so this is the first axis of every query.
            $table->string('action');

            // The mode the decision was MADE under. Without it a row is uninterpretable
            // once the mode flips: a Reject recorded under 'monitor' was let through,
            // the identical row under 'enforce' was blocked.
            $table->string('mode');

            // The scorer's verdict: allow | flag | challenge | step_up | reject.
            $table->string('outcome');

            // Decimal, not float: the whole point of the table is aggregation
            // (percentiles, histograms), and those must not drift on binary rounding.
            $table->decimal('score', 8, 2);

            // The human-readable reasons, and the per-signal weighted points behind the
            // total. Reasons explain a single decision; the points breakdown is what
            // lets an operator re-weight a signal rather than only move the threshold.
            $table->json('reasons');
            $table->json('signals');

            // HMAC-SHA256 under `app.key`, domain-separated. Never the raw values.
            $table->string('ip_hash', 64);
            $table->string('email_hash', 64)->nullable();

            // Mail domain in the clear — provider-level abuse patterns are the point.
            $table->string('email_domain')->nullable();

            $table->timestamp('assessed_at');

            // The tuning query: one action, one time window, grouped by outcome/score.
            $table->index(['action', 'assessed_at']);

            // The retention sweep (`model:prune`) filters on this alone.
            $table->index('assessed_at');

            // The investigation query: "this address turned out to be a bot — what did
            // the scorer say about it, and when?" A point lookup, so it gets an index;
            // `ip_hash` deliberately does not, because every IP question is a GROUP BY
            // over a window, which scans regardless.
            $table->index('email_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_decisions');
    }
};
