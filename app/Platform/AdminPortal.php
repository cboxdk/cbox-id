<?php

declare(strict_types=1);

namespace App\Platform;

use App\Models\AdminPortalLink;
use App\Platform\Enums\PortalFeature;
use App\Platform\Enums\PortalScope;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;

/**
 * Mints and redeems Admin Portal setup links, and owns the scoped "portal
 * session" an external IT admin holds while configuring one org's SSO/SCIM.
 *
 * The portal session is deliberately a DIFFERENT session key from the platform
 * login ({@see PlatformAuth::SESSION_KEY}), so it can never satisfy
 * `platform.auth` — it unlocks the setup screen and nothing else. The bound org
 * id lives ONLY in the server session; it is never read from client input, so a
 * redeemer cannot pivot to another tenant.
 */
final class AdminPortal
{
    /** The scoped portal session key — distinct from the platform login key. */
    public const SESSION_KEY = 'cbox.portal';

    public function __construct(
        private readonly AuditLog $audit,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * Mint a single-use link for an org and scope, returning the plaintext token
     * (shown to the minting admin once). Only its hash is persisted.
     */
    public function generate(string $organizationId, PortalScope $scope, string $createdBy): string
    {
        $token = bin2hex(random_bytes(32));

        $link = AdminPortalLink::create([
            'organization_id' => $organizationId,
            'scope' => $scope->value,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes($this->ttlMinutes()),
            'consumed_at' => null,
            'created_by' => $createdBy,
        ]);

        $this->audit->record(new AuditEvent(
            action: 'portal_link.created',
            actorType: ActorType::User,
            actorId: $createdBy,
            organizationId: $organizationId,
            targetType: 'admin_portal_link',
            targetId: $link->id,
            context: ['scope' => $scope->value],
        ));

        return $token;
    }

    /**
     * Redeem a token: if it maps to a live, unconsumed link whose org is still
     * entitled to the link's scope, CONSUME the link (single-use) and establish the
     * scoped portal session. A leaked/re-opened URL therefore cannot mint a second,
     * independent portal session — the session, not the link, carries the setup flow
     * from here. Any failure returns null with no enumeration detail.
     */
    public function redeem(string $token): ?AdminPortalLink
    {
        $link = AdminPortalLink::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($link === null || ! $link->isRedeemable()) {
            return null;
        }

        // Plan may have lapsed since the link was minted — re-gate at redemption.
        if (! $this->scopeEntitled($link->organization_id, PortalScope::tryFrom($link->scope))) {
            return null;
        }

        // Single-use: burn the link now so a second redemption of the same URL fails.
        $link->forceFill(['consumed_at' => now()])->save();

        // Regenerate the session id on this privilege elevation (as every other auth
        // path does) so a pre-fixed session cookie cannot ride the redemption into the
        // scoped portal — session fixation is especially reachable under a shared
        // parent SESSION_DOMAIN.
        session()->regenerate();

        session()->put(self::SESSION_KEY, [
            'link_id' => $link->id,
            'org' => $link->organization_id,
            'scope' => $link->scope,
            'expires' => $link->expires_at->getTimestamp(),
        ]);

        return $link;
    }

    /**
     * Mark the bound link consumed, record the completion, and clear the session.
     * Returns false when there is no valid bound link.
     */
    public function complete(): bool
    {
        $link = $this->currentLink();

        if ($link === null) {
            return false;
        }

        // The link was already burned at redemption (single-use); don't clobber that
        // timestamp — consumed_at should read as the REDEMPTION moment. Only stamp it
        // here for a legacy link redeemed before single-use landed.
        if ($link->consumed_at === null) {
            $link->forceFill(['consumed_at' => now()])->save();
        }

        $this->audit->record(new AuditEvent(
            // The redeemer is an external IT admin with no platform identity, so
            // the completion is recorded as a system-scoped event on the org trail.
            action: 'portal_link.completed',
            actorType: ActorType::System,
            actorId: null,
            organizationId: $link->organization_id,
            targetType: 'admin_portal_link',
            targetId: $link->id,
            context: ['scope' => $link->scope],
        ));

        $this->clearSession();

        return true;
    }

    /**
     * Whether the current portal session is valid RIGHT NOW. The link is consumed at
     * redemption (single-use), so validity is a property of the SESSION — its
     * unexpired window plus a live re-check that the org is still entitled to the
     * bound scope (catches a mid-session plan lapse). Re-evaluated on every call.
     */
    public function sessionValid(): bool
    {
        $data = $this->currentSession();

        if ($data === null || $data['expires'] < now()->getTimestamp()) {
            return false;
        }

        return $this->scopeEntitled($data['org'], PortalScope::tryFrom($data['scope']));
    }

    /**
     * Whether the bound session may configure a given feature — i.e. the feature is
     * in the link's scope AND the org is entitled to it.
     */
    public function canConfigure(PortalFeature $feature): bool
    {
        $data = $this->currentSession();

        if ($data === null) {
            return false;
        }

        $scope = PortalScope::tryFrom($data['scope']);

        if ($scope === null || ! $scope->permits($feature)) {
            return false;
        }

        return $this->entitlements->entitled($data['org'], $feature->entitlement());
    }

    /**
     * The org id bound to the portal session — the ONLY source of the org id the
     * setup screen ever acts on. Null when there is no portal session.
     */
    public function boundOrgId(): ?string
    {
        return $this->currentSession()['org'] ?? null;
    }

    public function boundScope(): ?PortalScope
    {
        $scope = $this->currentSession()['scope'] ?? null;

        return is_string($scope) ? PortalScope::tryFrom($scope) : null;
    }

    public function clearSession(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * The link backing the current portal session, or null.
     */
    public function currentLink(): ?AdminPortalLink
    {
        $data = $this->currentSession();

        return $data === null ? null : AdminPortalLink::query()->find($data['link_id']);
    }

    private function scopeEntitled(string $organizationId, ?PortalScope $scope): bool
    {
        if ($scope === null) {
            return false;
        }

        // A link is usable while the org is still entitled to ANY feature it covers.
        foreach ($scope->features() as $feature) {
            if ($this->entitlements->entitled($organizationId, $feature->entitlement())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{link_id: string, org: string, scope: string, expires: int}|null
     */
    private function currentSession(): ?array
    {
        $data = session()->get(self::SESSION_KEY);

        if (! is_array($data)) {
            return null;
        }

        $linkId = $data['link_id'] ?? null;
        $org = $data['org'] ?? null;
        $scope = $data['scope'] ?? null;
        $expires = $data['expires'] ?? null;

        if (! is_string($linkId) || ! is_string($org) || ! is_string($scope) || ! is_int($expires)) {
            return null;
        }

        return ['link_id' => $linkId, 'org' => $org, 'scope' => $scope, 'expires' => $expires];
    }

    private function ttlMinutes(): int
    {
        $ttl = config('cbox-id.portal.ttl_minutes', 30);

        return is_int($ttl) && $ttl > 0 ? $ttl : 30;
    }
}
