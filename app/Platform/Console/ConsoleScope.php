<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\CurrentUser;
use App\Platform\Entitlements;
use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The three questions every console page asks, answered once.
 *
 * The console existed twice — an organization-plane version and an environment-plane
 * version of every capability, thirteen pairs of independent components. They called the
 * same framework contracts, so the domain logic was never duplicated; what was duplicated
 * was this: *who is acting*, *on which organization*, and *what may they do*. Each pair
 * answered those differently, and the answers drifted. Directories offered Google and
 * Entra on one plane and SCIM on the other. Connections offered domain verification on
 * one and not the other. Entitlements were enforced on one and not the other. Nobody
 * decided any of that.
 *
 * So this is the seam. A page resolves a scope and stops caring which door the
 * administrator came through; the scope is the only thing in the console that knows.
 *
 * Bound `scoped`: the acting organization is chosen per request (the environment plane
 * carries a picker), and a singleton would leak one administrator's choice into the next
 * request on a long-lived worker.
 */
class ConsoleScope
{
    /** The environment plane's chosen organization, held so a picker survives navigation. */
    public const SELECTION_KEY = 'cbox.console.organization';

    public function __construct(
        private readonly CurrentUser $subject,
        private readonly EnvironmentAdminAuth $environmentAdmin,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * Which door this request came through, resolved from the session that exists.
     *
     * The subject session is checked FIRST and deliberately. The two stores are
     * independent and a browser can hold both — an account member who also has a subject
     * account in the same environment. If the environment plane won that tie, an ordinary
     * organization member holding a stale account session would silently be given the
     * ability to act on every organization in the environment.
     */
    public function plane(): ConsolePlane
    {
        return $this->subject->check()
            ? ConsolePlane::Organization
            : ConsolePlane::Environment;
    }

    /**
     * The organization being administered, or null when the environment plane has not
     * chosen one yet.
     *
     * On the organization plane this is not a choice: it is the organization the person
     * is a member of, and nothing in the request can change it.
     */
    public function organizationId(): ?string
    {
        if ($this->plane() === ConsolePlane::Organization) {
            return $this->subject->organizationId();
        }

        $chosen = session()->get(self::SELECTION_KEY);

        if (! is_string($chosen) || $chosen === '') {
            return null;
        }

        // Re-validated on every read, not trusted because it was validated when chosen.
        // The environment scope on Organization is what makes this safe: an id belonging
        // to another environment resolves to nothing here, so a session carried to a
        // different host cannot act on the organization it named.
        return array_key_exists($chosen, $this->availableOrganizations()) ? $chosen : null;
    }

    /**
     * Choose the organization to act on. Environment plane only.
     *
     * Refused rather than ignored on the organization plane: a member who could set this
     * would be choosing which organization to administer, which is precisely the
     * authorization the plane exists to withhold.
     *
     * @throws AuthorizationException
     */
    public function chooseOrganization(string $organizationId): void
    {
        if ($this->plane() !== ConsolePlane::Environment) {
            throw new AuthorizationException('Only an environment administrator may choose which organization to act on.');
        }

        if (! array_key_exists($organizationId, $this->availableOrganizations())) {
            throw new AuthorizationException('That organization is not in this environment.');
        }

        session()->put(self::SELECTION_KEY, $organizationId);
    }

    /**
     * The organizations this administrator may act on, id => name.
     *
     * A plain map rather than a Collection: this is a picker's option list — a
     * serialization edge — and `pluck()` returns a shape neither PHPStan nor a reader can
     * pin down, which is how a key silently becomes an int.
     *
     * @return array<string, string>
     */
    public function availableOrganizations(): array
    {
        if ($this->plane() === ConsolePlane::Organization) {
            $organization = $this->subject->organization();

            return $organization === null
                ? []
                : [$organization->id => $organization->name];
        }

        $available = [];

        // Environment-scoped by the model's own global scope, so this is every
        // organization in THIS environment and never another's.
        foreach (Organization::query()->orderBy('name')->get(['id', 'name']) as $organization) {
            $available[$organization->id] = $organization->name;
        }

        return $available;
    }

    /**
     * The route name for this capability on the plane we are actually on.
     *
     * The two planes name the same page differently — `governance.show` and
     * `environment.governance.show` — so a shared component cannot hard-code either. It
     * is a prefix and nothing more, but hard-coding one of them is how a merged page
     * redirects an environment administrator onto a route that 404s for them.
     */
    public function routeName(string $name): string
    {
        return $this->plane() === ConsolePlane::Environment ? 'environment.'.$name : $name;
    }

    /**
     * WHO is acting — the id that goes in the audit trail.
     *
     * The subject on both planes, because the subject is the credential of record: an
     * account member is not a credential store, it points AT a subject. The two consoles
     * disagreed about this. The organization plane recorded the subject id; the
     * environment plane recorded the AccountMember row id, which lives in a different
     * table entirely.
     *
     * So an access-review certification was attributed to one id space or the other
     * depending which console the reviewer happened to use — in the one feature whose
     * entire output is a trail somebody later has to read. Half those ids resolve against
     * `users` and half against `account_members`, and nothing recorded which.
     *
     * Returns '' when nobody is acting, which callers must treat as "do not attribute"
     * rather than as an id.
     */
    public function actorId(): string
    {
        if ($this->plane() === ConsolePlane::Environment) {
            return $this->environmentAdmin->subjectId() ?? '';
        }

        return $this->subject->id();
    }

    /**
     * Whether this administrator may change things, as opposed to look at them.
     *
     * An environment administrator holds the environment; there is no lesser role on that
     * plane, so reaching the console at all is the authorization. On the organization
     * plane it is the membership role.
     */
    public function mayAdminister(): bool
    {
        if ($this->plane() === ConsolePlane::Environment) {
            return $this->environmentAdmin->check();
        }

        return $this->subject->isAdmin();
    }

    /** @throws AuthorizationException */
    public function assertMayAdminister(): void
    {
        if (! $this->mayAdminister()) {
            throw new AuthorizationException('You do not have permission to change this.');
        }
    }

    /**
     * Whether the acting organization is entitled to a feature.
     *
     * Enforced on BOTH planes, which it was not before: fifteen `guardEntitled()` calls
     * lived on the organization plane and none on the environment plane, so switching
     * consoles walked past the gate. An entitlement belongs to the organization, not to
     * the door — and the environment console is exactly where someone would go to get
     * around it. The reader is open by default today, so this changed nothing visible;
     * it changes everything the moment a real reader is bound, which is when nobody
     * would have noticed the hole.
     */
    public function entitled(string $feature): bool
    {
        $organizationId = $this->organizationId();

        return $organizationId !== null && $this->entitlements->entitled($organizationId, $feature);
    }

    /** @throws AuthorizationException */
    public function assertEntitled(string $feature): void
    {
        if (! $this->entitled($feature)) {
            throw new AuthorizationException('This organization does not have access to that feature.');
        }
    }

    /**
     * The organization to act on, or a refusal.
     *
     * Callers that are about to WRITE use this rather than the nullable reader, so a
     * write can never be attempted with no organization resolved — which on the
     * environment plane would otherwise mean writing into whichever organization a
     * downstream default picked.
     *
     * @throws AuthorizationException
     */
    public function requireOrganizationId(): string
    {
        $organizationId = $this->organizationId();

        if ($organizationId === null) {
            throw new AuthorizationException('Choose an organization before making changes.');
        }

        return $organizationId;
    }
}
