<?php

declare(strict_types=1);

namespace App\Platform;

use App\Models\RiskDecision;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Risk\ValueObjects\RiskAssessment;
use Cbox\Risk\ValueObjects\SignalResult;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Persists risk decisions so they can be queried later.
 *
 * ## Why this exists
 *
 * {@see RiskGuard} used to `Log::info` each decision and call that "the audit trail
 * for tuning and review". Production runs `LOG_CHANNEL=stderr` with no aggregation
 * and no `log_streams` sink, so every decision went to pod stdout and died on the
 * next rollout. Monitor mode ran for weeks and left nothing to tune against: there
 * was no way to answer "what did the scorer say about the five bot signups on 20–23
 * July, and what did it say about everyone else?" A control you cannot measure cannot
 * be turned on.
 *
 * ## Why not the audit chain
 *
 * `Cbox\Id\Kernel\Audit\Contracts\AuditLog` is durable and already streamed, and it
 * was the obvious candidate. It is the wrong home here:
 *
 *  - Every append opens a transaction and takes `SELECT ... FOR UPDATE` on the chain
 *    head for its (environment, scope). Single-writer that is only ~70% slower than a
 *    plain insert (0.55ms vs 0.32ms p50, local SQLite) — tolerable on its own.
 *  - CONCURRENTLY it does not merely slow down, it FAILS. Measured on PostgreSQL 16:
 *    two concurrent writers, 100 appends each — 100 succeeded, 100 died on
 *    `audit_logs_environment_id_scope_sequence_unique` (23505). At eight writers,
 *    700 of 800 died. Under READ COMMITTED a blocked `FOR UPDATE` re-checks the
 *    locked ROW, not the `ORDER BY ... LIMIT 1` that selected it, so the waiter wakes
 *    holding a stale head and takes a sequence that is already gone.
 *    `DB::transaction(..., attempts: 3)` does not cover it: Laravel retries deadlock
 *    and serialization failures, and a unique violation is neither.
 *  - These writes happen BEFORE authentication. Putting them on the chain would hand
 *    an unauthenticated client — a credential-stuffing burst, precisely the traffic
 *    the scorer exists to catch — the ability to drive that contention and starve the
 *    audit writes of everyone who IS authenticated. An availability and integrity
 *    regression bolted onto an observability fix.
 *  - `audit_logs` is deliberately never pruned (pruning it would break the
 *    tamper-evidence). Pre-auth telemetry from unauthenticated traffic must not grow
 *    forever inside a structure that cannot shed it.
 *
 * So this is a dedicated table with its own retention. The cost is a second store to
 * reason about, and no SIEM streaming for these rows today. Genuine security EVENTS
 * (a block, a step-up demand) still belong on the audit chain when enforcement lands
 * — those are rare and already post-decision; scoring every attempt is not.
 *
 * ## Personal data
 *
 * The IP is HMAC-hashed under `app.key`, as it always was (the risk package's GDPR
 * guidance). The email is treated the same way: a keyed pseudonym, never the address.
 * That keeps the highest-volume, pre-auth, unauthenticated-traffic table in the
 * platform from being its largest plaintext store of personal data, while staying
 * exactly correlatable — an operator investigating a known-bad address hashes it and
 * looks it up ({@see emailPseudonym()}). The mail DOMAIN is kept in the clear: it
 * describes a provider, not a person, and disposable/throwaway-provider patterns are
 * invisible without it.
 *
 * The pseudonyms are keyed, not bare digests, so the table is not brute-forceable
 * from itself — recovering an address needs `app.key`, the same secret that already
 * protects sessions and encrypted columns.
 */
final class RiskTrail
{
    /**
     * Record one decision. Returns null when the write could not be made.
     *
     * FAILS OPEN, always. This is telemetry on the sign-in path: a full disk or a
     * migration that has not run yet must degrade observability, never sign-in. The
     * failure is logged at warning so a silently-empty trail is still noticeable.
     */
    public function record(string $action, ?string $ip, ?string $email, RiskAssessment $assessment): ?RiskDecision
    {
        try {
            $decision = new RiskDecision;
            $decision->fill([
                // Resolved per call, never captured: EnvironmentContext is a scoped
                // binding and a long-lived holder would pin the first request's value.
                'environment_id' => app(EnvironmentContext::class)->current()?->environmentKey(),
                'action' => $action,
                'mode' => $this->mode(),
                'outcome' => $assessment->outcome,
                'score' => round($assessment->score, 2),
                'reasons' => $assessment->reasons(),
                'signals' => $this->signalPoints($assessment),
                'ip_hash' => $this->ipPseudonym($ip),
                'email_hash' => $email === null || trim($email) === '' ? null : $this->emailPseudonym($email),
                'email_domain' => $this->domainOf($email),
            ]);
            $decision->save();

            return $decision;
        } catch (Throwable $e) {
            Log::warning('risk decision could not be recorded', [
                'action' => $action,
                'outcome' => $assessment->outcome->value,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The stable pseudonym for an email address, so an operator who already knows an
     * address can find its decisions:
     *
     *     App\Models\RiskDecision::where('email_hash', app(App\Platform\RiskTrail::class)->emailPseudonym('bot@gmail.com'))->get();
     *
     * Canonicalisation is trim + lowercase and NOTHING else. Provider-specific
     * equivalences (Gmail ignores dots and `+tags`, most other providers do not) are
     * deliberately not folded in: guessing them wrong merges two different people into
     * one pseudonym. To chase a dot-abuse family, hash each variant, or — better —
     * query `email_domain` plus score, which is what that pattern actually looks like
     * in aggregate.
     */
    public function emailPseudonym(string $email): string
    {
        return $this->pseudonym('email', strtolower(trim($email)));
    }

    /**
     * The stable pseudonym for a client IP. A null IP still gets a hash (of the empty
     * string) rather than a null column, so "unknown source" is one bucket and not a
     * hole in a GROUP BY.
     */
    public function ipPseudonym(?string $ip): string
    {
        return $this->pseudonym('ip', (string) $ip);
    }

    /**
     * Keyed HMAC under `app.key`, domain-separated by `$kind` so an IP pseudonym and
     * an email pseudonym can never collide or be joined to each other by accident.
     */
    private function pseudonym(string $kind, string $value): string
    {
        $appKey = config('app.key');

        return hash_hmac('sha256', $kind.':'.$value, is_string($appKey) ? $appKey : '');
    }

    /**
     * Per-signal weighted points, keyed by signal. This is what makes a re-WEIGHTING
     * possible: the reasons say what fired, these say how much each one moved the
     * score, so an operator can see that (say) `velocity` alone is dragging legitimate
     * traffic over the line before they touch the threshold.
     *
     * @return array<string, float>
     */
    private function signalPoints(RiskAssessment $assessment): array
    {
        $points = [];

        foreach ($assessment->signals as $signal) {
            /** @var SignalResult $signal */
            $points[$signal->key] = round($signal->points, 2);
        }

        return $points;
    }

    private function mode(): string
    {
        $mode = config('risk.mode', 'monitor');

        return is_string($mode) ? $mode : 'monitor';
    }

    private function domainOf(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $at = strrpos($email, '@');

        if ($at === false) {
            return null;
        }

        $domain = strtolower(trim(substr($email, $at + 1)));

        return $domain === '' ? null : $domain;
    }
}
