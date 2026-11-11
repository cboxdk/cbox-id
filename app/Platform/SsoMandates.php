<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\PlatformRoot;

/**
 * Which of a subject's organizations refused their sign-in, and where to send them.
 *
 * {@see PlatformAuth::localSignInAllowedFor()} answers whether there is still a local way
 * in and deliberately answers nothing else — it is a gate, and a gate that returned an
 * explanation would tempt callers into treating the explanation as the decision. This
 * asks the same memberships the same question a second time, for the screen rather than
 * for the door, and it runs only on the refusal path.
 *
 * One lookup for every door. The doors that cannot render their own refusal hand the
 * subject to a sign-in screen through {@see SsoRefusal}, and the screen asks THIS — so a
 * magic link, a passkey and a password all end up naming the same organization and
 * linking to the same connection, because they all end up here.
 *
 * Home-realm discovery ({@see DomainVerification::connectionForEmail()})
 * already routes people whose EMAIL DOMAIN is verified against an active connection, so
 * everybody who gets this far is somebody discovery could not place: their address is at
 * a domain the organization has not verified, or has verified with capture turned off.
 * The mandate is a fact about the organization rather than about the address, so it can
 * still name the connection — which is the whole reason this exists.
 */
final readonly class SsoMandates
{
    /** The `amr` value the framework's federation service stamps on an enterprise SSO session. */
    private const SSO_METHOD = 'sso';

    public function __construct(
        private Memberships $memberships,
        private AuthPolicies $policies,
        private Connections $connections,
        private PlatformRoot $platformRoot,
    ) {}

    /**
     * The first of the subject's organizations that mandates SSO, or null when none does.
     *
     * FIRST rather than all of them, and that is not a shortcut: the rule the doors
     * enforce is "any mandating membership refuses the sign-in", so one is enough to
     * explain the refusal, and listing every organization a person belongs to on a
     * pre-session screen would publish their affiliations to whoever reached it.
     */
    public function forSubject(string $subjectId): ?SsoMandate
    {
        // TWO PLACES, because this one method answers for two kinds of person. A tenant's
        // end user holds memberships in the environment they belong to, which is the
        // ambient one; a CUSTOMER's people hold theirs in the platform root. Memberships
        // and auth policies are both environment-owned, so looking in only one place
        // reports "no mandate" for everybody in the other — and no mandate fails OPEN,
        // telling an organization that requires SSO that its people may use a password.
        //
        // Ambient first: it is the common case and the one that needs no scope switch.
        return $this->mandateIn($subjectId)
            ?? $this->platformRoot->run(fn (): ?SsoMandate => $this->mandateIn($subjectId));
    }

    /**
     * Whether a mandate is a rule about ENTERING AN ORGANIZATION rather than about the
     * person (`cbox-id.sso.mandate_per_organization`).
     *
     * Per person, the original rule: any membership of an organization that requires SSO
     * closes every local door to that person, everywhere. Per organization: the person
     * signs in to the environment however it allows, and an organization that requires
     * SSO is entered (its console opened, an app authorized for it) only on a session that
     * came through that organization's own connection. Their other organizations, and
     * their own account, are untouched by it.
     */
    public static function perOrganization(): bool
    {
        return config('cbox-id.sso.mandate_per_organization') === true;
    }

    /** Whether this organization requires SSO of the people entering it. */
    public function mandates(string $organizationId): bool
    {
        return ! $this->policies->resolve($organizationId)->sso->allowsPasswordLogin();
    }

    /**
     * Whether this session may ENTER the organization, as far as its SSO mandate goes.
     * Always true while mandates are per person: that rule is enforced at the doors.
     */
    public function admits(?Session $session, string $organizationId): bool
    {
        return $session === null
            ? ! self::perOrganization() || ! $this->mandates($organizationId)
            : $this->admitsMethods(array_values($session->amr), $session->organization_id, $organizationId);
    }

    /**
     * {@see admits()} for a session described by how it was (or is about to be)
     * established: its `amr` and the organization it was started for.
     *
     * "THROUGH THE ORGANIZATION'S CONNECTION" IS READ FROM TWO FACTS THE SESSION ALREADY
     * CARRIES. The framework starts an enterprise SSO session with `amr = ['sso']` and
     * `organization_id` = the connection's organization, and nothing rewrites either
     * afterwards (switching organization moves the browser's selection, not the row). So
     * `sso` + this organization means an assertion from one of this organization's
     * enterprise connections opened it. A catalogue provider scoped to the organization
     * is `social`, and an environment-wide connection carries no organization: neither
     * satisfies a mandate, as neither is the door the administrator mandated.
     *
     * A support session (`impersonation`) is admitted: it is an operator's authorized
     * view of one organization, not the person's own sign-in.
     *
     * @param  list<mixed>  $amr
     */
    public function admitsMethods(array $amr, ?string $sessionOrganizationId, string $organizationId): bool
    {
        if (! self::perOrganization() || ! $this->mandates($organizationId)) {
            return true;
        }

        if (in_array('impersonation', $amr, true)) {
            return true;
        }

        return in_array(self::SSO_METHOD, $amr, true) && $sessionOrganizationId === $organizationId;
    }

    /** The organization's name and where its people start SSO, for a step-up or a refusal. */
    public function forOrganization(string $organizationId): SsoMandate
    {
        return $this->describe($organizationId);
    }

    /** The strictest mandate among this subject's memberships IN THE CURRENT SCOPE. */
    private function mandateIn(string $subjectId): ?SsoMandate
    {
        foreach ($this->memberships->forUser($subjectId) as $membership) {
            $organizationId = $membership->organization_id;

            if ($this->policies->resolve($organizationId)->sso->allowsPasswordLogin()) {
                continue;
            }

            return $this->describe($organizationId);
        }

        return null;
    }

    /**
     * The organization's name and the URL its people start SSO at.
     *
     * `forOrganization()` is the ENTERPRISE connection, excluding the catalogue-backed
     * providers a tenant offers as buttons — pointing a mandated sign-in at "Continue
     * with Google" would send somebody to a door their administrator did not mandate.
     * The status is re-asserted here rather than assumed: the contract does not promise
     * an active row, and a link to a disabled connection is a second dead end wearing the
     * clothes of a fix. No connection at all yields null, and the screen says so.
     */
    private function describe(string $organizationId): SsoMandate
    {
        $name = Organization::query()->whereKey($organizationId)->value('name');
        $connection = $this->connections->forOrganization($organizationId);

        return new SsoMandate(
            organizationName: is_string($name) && $name !== '' ? $name : 'Your organization',
            startUrl: $connection instanceof Connection && $connection->isActive()
                ? SsoStart::url($connection)
                : null,
        );
    }
}
