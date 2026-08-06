<?php

declare(strict_types=1);

namespace Cbox\Id\RiskPlus\Support;

use Cbox\Id\Kernel\Tenancy\Concerns\ResolvesEnvironment;
use Cbox\Risk\ValueObjects\RiskContext;

/**
 * Derives the per-account key that the adaptive signals track history against.
 *
 * Impossible-travel and new-device are per-*account* checks, so they key on who is
 * signing in — the email when present, or an explicit `subject` attribute the host
 * can set on the context. The value is HMAC'd with the app key before it's ever
 * used as a cache key, so no raw email/identifier is stored (same discipline as
 * laravel-risk's velocity signal). Returns null when there's nothing to key on, so
 * the signal cleanly no-ops rather than tracking anonymous traffic.
 *
 * The ENVIRONMENT is folded in before the HMAC, and that is not tidiness. The secret is
 * `app.key`, identical across every environment of a deployment, and the assessment runs
 * BEFORE authentication on an email the caller supplies — so without it, an
 * unauthenticated attacker on their own free-trial tenant wrote into the shared history
 * of any address they cared to name. They could seed their own device fingerprint so the
 * victim's real tenant no longer scored `device.new` and stopped demanding a step-up;
 * evict every genuine device by filling the capped set; or plant a distant location so
 * the victim's next legitimate sign-in was flagged as impossible travel — friction, or a
 * refusal outright under `risk.mode=enforce`. The differing outcomes were also a
 * cross-tenant oracle for whether an account exists and roughly where it signs in from.
 */
class SubjectKey
{
    use ResolvesEnvironment;

    public function __construct(private string $secret) {}

    public function for(RiskContext $context): ?string
    {
        $subject = $context->attribute('subject');
        $raw = is_string($subject) && $subject !== '' ? $subject : $context->email;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        // Resolved per call, never captured. This is bound as a singleton and
        // `EnvironmentContext` is scoped: a constructor-injected copy would be the FIRST
        // request's context for the life of the process, and a queue worker would key
        // job B's history under job A's tenant. That exact mistake has been made four
        // times in this codebase, which is why the trait exists.
        $environment = $this->environments()->current()?->environmentKey() ?? 'no-env';

        return hash_hmac('sha256', $environment.'|'.strtolower($raw), $this->secret);
    }
}
