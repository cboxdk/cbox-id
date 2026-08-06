<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Contracts\AuthPolicies;
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
