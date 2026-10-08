<?php

declare(strict_types=1);

namespace App\Platform;

use App\Models\AdminPortalLink;
use App\Platform\Actions\Principal\PortalPrincipal;
use App\Platform\Enums\PortalIntent;
use App\Platform\Enums\PortalScope;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;

/**
 * Mints and redeems Admin Portal setup links, and owns the scoped "portal
 * session" an external IT admin holds while setting up what the link covers for
 * one org — its {@see PortalScope}, a set of {@see PortalIntent}s.
 *
 * The portal session is deliberately a DIFFERENT session key from the platform
 * login ({@see PlatformAuth::SESSION_KEY}), so it can never satisfy
 * `platform.auth` — it unlocks the setup screens and nothing else. The bound org
 * id lives ONLY in the server session; it is never read from client input, so a
 * redeemer cannot pivot to another tenant.
 *
 * What the session may DO is a principal's question, not this class's: every write the
 * portal makes runs as an action under {@see PortalPrincipal}, built here from the
 * session ({@see principal()}), which refuses any action its intents do not cover.
 */
final class AdminPortal
{
    /** The scoped portal session key — distinct from the platform login key. */
    public const SESSION_KEY = 'cbox.portal';

    /** The longest a link may wait to be opened: a week, for a link sent by mail. */
    public const MAX_TTL_MINUTES = 10080;

    /** The shortest. Anything less expires while the mail is still in flight. */
    public const MIN_TTL_MINUTES = 5;

    public function __construct(
        private readonly AuditLog $audit,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * Mint a single-use link for an org and scope, returning the plaintext token
     * (shown to the minting admin once). Only its hash is persisted.
     */
    public function generate(string $organizationId, PortalScope $scope, string $createdBy, ?int $ttlMinutes = null): string
    {
        return $this->issue($organizationId, $scope, $createdBy, $ttlMinutes)['token'];
    }

    /**
     * Mint a link and hand back both the stored row and the plaintext token — the token is
     * never readable from the row again. `$ttlMinutes` is how long it may wait unopened,
     * clamped to {@see MIN_TTL_MINUTES}..{@see MAX_TTL_MINUTES}; null is the configured default.
     *
     * @return array{link: AdminPortalLink, token: string}
     */
    public function issue(string $organizationId, PortalScope $scope, string $createdBy, ?int $ttlMinutes = null, ?string $emailedTo = null): array
    {
        $token = bin2hex(random_bytes(32));
        $ttl = max(self::MIN_TTL_MINUTES, min(self::MAX_TTL_MINUTES, $ttlMinutes ?? $this->ttlMinutes()));

        $link = AdminPortalLink::create([
            'organization_id' => $organizationId,
            'intents' => $scope->values(),
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes($ttl),
            'consumed_at' => null,
            'created_by' => $createdBy,
            'emailed_to' => $emailedTo,
        ]);

        $this->audit->record(new AuditEvent(
            action: 'portal_link.created',
            actorType: ActorType::User,
            actorId: $createdBy,
            organizationId: $organizationId,
            targetType: 'admin_portal_link',
            targetId: $link->id,
            context: array_filter([
                'intents' => $scope->values(),
                'expires_at' => $link->expires_at->toIso8601String(),
                'emailed_to' => $emailedTo,
            ], static fn (mixed $value): bool => $value !== null),
        ));

        return ['link' => $link, 'token' => $token];
    }

    /**
     * Redeem a token: if it maps to a live, unconsumed link whose org may still use
     * something the link covers, CONSUME the link (single-use) and establish the scoped
     * portal session. A leaked/re-opened URL therefore cannot mint a second, independent
     * portal session — the session, not the link, carries the setup flow from here. Any
     * failure returns null with no enumeration detail.
     */
    public function redeem(string $token): ?AdminPortalLink
    {
        $link = AdminPortalLink::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($link === null || ! $link->isRedeemable()) {
            return null;
        }

        $scope = $link->portalScope();

        // Plan may have lapsed since the link was minted — re-gate at redemption.
        if ($scope === null || $this->usable($link->organization_id, $scope) === []) {
            return null;
        }

        // Single-use: burn the link now so a second redemption of the same URL fails.
        $link->forceFill(['consumed_at' => now()])->save();

        // Regenerate the session id on this privilege elevation (as every other auth
        // path does) so a pre-fixed session cookie cannot ride the redemption into the
        // scoped portal — session fixation is especially reachable under a shared
        // parent SESSION_DOMAIN.
        session()->regenerate();

        /*
         * THE SESSION'S OWN WINDOW, from the moment it was opened. A link may wait a week in
         * somebody's inbox; the setup it opens should not then be cut short by how long it
         * waited, nor last a week because the link could. Setting up an identity provider
         * takes an afternoon, and that is what the session gets.
         */
        session()->put(self::SESSION_KEY, [
            'link_id' => $link->id,
            'org' => $link->organization_id,
            'intents' => $scope->values(),
            'created_by' => $link->created_by,
            'expires' => now()->addMinutes($this->sessionMinutes())->getTimestamp(),
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
            // The redeemer is an external IT admin with no platform identity, so the
            // completion is the portal session's own act — the system, named by the link
            // that opened it, exactly as {@see PortalPrincipal::auditActor()} names it.
            action: 'portal_link.completed',
            actorType: ActorType::System,
            actorId: $link->id,
            organizationId: $link->organization_id,
            targetType: 'admin_portal_link',
            targetId: $link->id,
            context: [
                'intents' => $link->portalScope()?->values() ?? [],
                PortalPrincipal::CREATED_BY => $link->created_by,
            ],
        ));

        $this->clearSession();

        return true;
    }

    /**
     * Whether the current portal session is valid RIGHT NOW. The link is consumed at
     * redemption (single-use), so validity is a property of the SESSION — its
     * unexpired window plus a live re-check that the org may still use something the
     * link covers (catches a mid-session plan lapse). Re-evaluated on every call.
     */
    public function sessionValid(): bool
    {
        $data = $this->currentSession();

        if ($data === null || $data['expires'] < now()->getTimestamp()) {
            return false;
        }

        return $this->usable($data['org'], $data['scope']) !== [];
    }

    /**
     * Whether the bound session may set up $intent — the link covers it AND the org's plan
     * includes it, asked live.
     */
    public function canConfigure(PortalIntent $intent): bool
    {
        $data = $this->currentSession();

        return $data !== null && $data['scope']->has($intent) && $this->intentUsable($data['org'], $intent);
    }

    /**
     * The intents the session can use right now, in display order: what the link covers,
     * less whatever the organization's plan has since stopped including.
     *
     * @return list<PortalIntent>
     */
    public function usableIntents(): array
    {
        $data = $this->currentSession();

        return $data === null ? [] : $this->usable($data['org'], $data['scope']);
    }

    /**
     * The org id bound to the portal session — the ONLY source of the org id the
     * setup screens ever act on. Null when there is no portal session.
     */
    public function boundOrgId(): ?string
    {
        return $this->currentSession()['org'] ?? null;
    }

    public function boundScope(): ?PortalScope
    {
        return $this->currentSession()['scope'] ?? null;
    }

    /**
     * The principal every portal write runs as: this session's link, its organization and
     * the intents it can still use. Null without a valid session — there is nobody to act.
     */
    public function principal(): ?PortalPrincipal
    {
        $data = $this->currentSession();

        if ($data === null || ! $this->sessionValid()) {
            return null;
        }

        return new PortalPrincipal(
            linkId: $data['link_id'],
            organizationId: $data['org'],
            scope: PortalScope::of($this->usable($data['org'], $data['scope'])),
            createdBy: $data['created_by'],
        );
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

    /**
     * Whether the organization may use $intent: always, for an intent no plan gates.
     */
    public function intentUsable(string $organizationId, PortalIntent $intent): bool
    {
        $feature = $intent->entitlement();

        return $feature === null || $this->entitlements->entitled($organizationId, $feature);
    }

    /**
     * @return list<PortalIntent>
     */
    private function usable(string $organizationId, PortalScope $scope): array
    {
        return array_values(array_filter(
            $scope->intents,
            fn (PortalIntent $intent): bool => $this->intentUsable($organizationId, $intent),
        ));
    }

    /**
     * @return array{link_id: string, org: string, scope: PortalScope, created_by: string, expires: int}|null
     */
    private function currentSession(): ?array
    {
        $data = session()->get(self::SESSION_KEY);

        if (! is_array($data)) {
            return null;
        }

        $linkId = $data['link_id'] ?? null;
        $org = $data['org'] ?? null;
        $scope = PortalScope::fromStored($data['intents'] ?? null);
        $createdBy = $data['created_by'] ?? null;
        $expires = $data['expires'] ?? null;

        if (! is_string($linkId) || ! is_string($org) || $scope === null || ! is_int($expires)) {
            return null;
        }

        return [
            'link_id' => $linkId,
            'org' => $org,
            'scope' => $scope,
            'created_by' => is_string($createdBy) ? $createdBy : '',
            'expires' => $expires,
        ];
    }

    private function ttlMinutes(): int
    {
        $ttl = config('cbox-id.portal.ttl_minutes', 30);

        return is_int($ttl) && $ttl > 0 ? $ttl : 30;
    }

    private function sessionMinutes(): int
    {
        $minutes = config('cbox-id.portal.session_minutes', 120);

        return is_int($minutes) && $minutes > 0 ? $minutes : 120;
    }
}
