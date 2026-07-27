<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Kernel\Authorization\CachedEntitlements;
use Cbox\Id\Kernel\Authorization\Contracts\EntitlementReader;
use Cbox\Id\Kernel\Authorization\DefaultPolicyDecisionPoint;
use Cbox\Id\Kernel\Authorization\Enums\EnforcementMode;
use Cbox\Id\Kernel\Authorization\Enums\EntitlementSource;
use Cbox\Id\Kernel\Authorization\ValueObjects\EntitlementValue;
use Illuminate\Contracts\Config\Repository;

/**
 * Turns the entitlement projection's deny-by-default into grant-by-default, for a
 * deployment that has no billing plane to say otherwise.
 *
 * A FLOOR, NOT AN OVERRIDE. An explicit row always wins, so a self-hoster can still
 * differentiate organizations by writing entitlements by hand — including revoking
 * one by storing `enabled: false`. Only the ABSENCE of a row changes meaning: it
 * used to mean "denied", and here it means "nobody has said otherwise, so yes".
 *
 * Wrapped around {@see CachedEntitlements} unconditionally, and inert unless
 * `cbox-id.entitlements.mode` is `open` — the default, and what every self-host
 * runs. The hosted plane sets `metered`, keeps deny-by-default, and lets the
 * billing projection feed it.
 *
 * SAFE BECAUSE ENTITLEMENTS ARE NOT AN ISOLATION CONTROL. The authorization path,
 * {@see DefaultPolicyDecisionPoint::decide()}, resolves
 * allow/deny purely from the relationship store; entitlements sit beside it as a
 * separate accessor. Tenant isolation comes from the environment scope and
 * organization scoping, and every entitlement read is already keyed by an
 * organization id the caller had to prove (the decision endpoint takes it from the
 * introspected token, never from the request body). Granting an absent key
 * therefore widens which FEATURES an org sees, never whose DATA it can reach.
 *
 * Two deliberate asymmetries:
 *
 *  - `all()` is passed through untouched. The key space is open, so there is no
 *    set of "all possible entitlements" to synthesise — and because the token
 *    issuer mints the `ent` claim from `all()`, this also keeps tokens honest:
 *    they carry only entitlements someone actually granted, and do not balloon.
 *  - a synthesised grant is {@see EnforcementMode::DecisionApi}, never `Claims`,
 *    so it is resolved live on every check and can never be baked into a token
 *    that would outlive a later decision to start metering.
 *
 * Numeric limits are NOT fabricated: the synthesised value carries `enabled` and
 * nothing else, so `EntitlementValue::int('seats')` stays null — "no limit was
 * stated" — rather than inventing a number a caller might enforce.
 */
final class OpenEntitlements implements EntitlementReader
{
    public function __construct(
        private readonly EntitlementReader $inner,
        private readonly Repository $config,
    ) {}

    public function get(string $organizationId, string $key): ?EntitlementValue
    {
        $explicit = $this->inner->get($organizationId, $key);

        if ($explicit !== null || $this->metered()) {
            return $explicit;
        }

        return new EntitlementValue(
            key: $key,
            value: ['enabled' => true],
            mode: EnforcementMode::DecisionApi,
            source: EntitlementSource::System,
            version: 0,
        );
    }

    /**
     * Read per call, not per construction. This decorator is installed
     * unconditionally and is a pass-through under `metered`, because the mode is
     * consulted here rather than when the binding resolves. Deciding at bind time
     * looked tidier and was wrong: JwtTokenIssuer and DefaultPolicyDecisionPoint
     * are singletons that capture the reader in their constructors, so a
     * deployment (or a test) that changed the mode after they were first resolved
     * would keep the old behaviour with nothing to show for it.
     */
    private function metered(): bool
    {
        return $this->config->get('cbox-id.entitlements.mode') === 'metered';
    }

    /**
     * @return array<string, EntitlementValue>
     */
    public function all(string $organizationId): array
    {
        return $this->inner->all($organizationId);
    }
}
