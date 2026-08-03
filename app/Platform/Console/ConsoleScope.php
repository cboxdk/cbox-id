<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\CurrentUser;
use App\Platform\Entitlements;
use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Models\PlatformOperator;
use Cbox\Id\Platform\PlatformRoot;
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

    private bool $operatorResolved = false;

    private ?PlatformOperator $operatorRecord = null;

    public function __construct(
        private readonly CurrentUser $subject,
        private readonly EnvironmentAdminAuth $environmentAdmin,
        private readonly Entitlements $entitlements,
        private readonly PlatformOperators $operators,
    ) {}

    /**
     * Which door this request came through, resolved from the session that exists.
     *
     * The subject session is checked FIRST and deliberately. A browser can hold both an
     * ordinary subject session and an environment-admin binding — the same person, two
     * altitudes. If the environment plane won that tie, an ordinary organization member
     * standing on a host they happen to have an admin binding for would be given the
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
            $organizationId = $this->subject->organizationId();

            // An organization-plane session with no organization is not a state the
            // console can render anything for, and it must not be reported as null —
            // because null here means "an environment administrator has not chosen yet",
            // and every read in the console is written as
            // `when($id !== null, fn ($q) => $q->where('organization_id', $id))`.
            //
            // Those two readings of null collapsed into one answer, so a member whose
            // membership had gone — the organization deleted, or they were removed — was
            // handed the unfiltered query and saw every other organization's rows. I put
            // that idiom in the worked example six merges copied; half of them caught it
            // independently and fenced their own reads, half did not. Refusing here makes
            // the idiom correct everywhere by construction rather than by vigilance.
            if ($organizationId === null) {
                throw new AuthorizationException('Your account is not a member of an organization.');
            }

            return $organizationId;
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
     * Whether THIS PLATFORM has confirmed the acting administrator's address.
     *
     * Asked here rather than off CurrentUser, which is the organization plane's answer
     * and empty on the other one. VerifiedEmailGate read it directly, so on the
     * environment plane it answered "unverified" for every operator and refused every
     * creation — and four merges independently worked around that by calling the gate on
     * one plane only. Four private answers to one question is the divergence this whole
     * seam exists to remove.
     *
     * An environment administrator is a subject of the PLATFORM ROOT, not of the
     * environment they are administering, so the lookup runs in the root's scope. The
     * environment scope is deny-by-default, so without that it would find nothing and
     * report a verified operator as unverified — the same failure in a new place.
     */
    public function actorEmailVerified(): bool
    {
        if ($this->plane() === ConsolePlane::Organization) {
            return $this->subject->emailVerified();
        }

        $actorId = $this->actorId();

        if ($actorId === '') {
            return false;
        }

        return app(PlatformRoot::class)->run(
            fn (): bool => app(Subjects::class)->find($actorId)?->emailVerified === true,
        ) === true;
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
     *
     * One answer for both planes now, rather than one per plane that happened to agree.
     * The environment console used to read its own session key; there is one session, so
     * there is one place to read.
     */
    public function actorId(): string
    {
        return $this->environmentAdmin->subjectId() ?? $this->subject->id();
    }

    /**
     * Whether the person acting also runs this deployment.
     *
     * Asked of the SESSION THAT ALREADY EXISTS, which is the point. The platform pages —
     * environments, accounts, operators, platform security — used to sit behind their own
     * host prefix, their own layout and their own credential prompt, and that separation
     * was never a security boundary: it was a consequence of `platform_operators` being a
     * second credential store. It no longer is, so the pages become areas in the one
     * console that appear for whoever may see them, like every other area already does.
     *
     * Suspension is handled inside the framework lookup rather than here. Authority now
     * rides an existing session, and suspending an operator does not revoke their subject
     * sessions — so a check that only ran at sign-in would take away tomorrow's access
     * while leaving today's untouched.
     *
     * False for an environment administrator. An environment admin holds ONE environment
     * on behalf of an account; an operator holds the deployment above every account. That
     * an environment admin cannot become an operator by switching consoles is the one
     * separation worth keeping, and it is a fact about the subject, not about the door.
     */
    public function isPlatformOperator(): bool
    {
        return $this->operator() !== null;
    }

    /**
     * The operator record behind this session, or null.
     *
     * Memoised per request: the rail asks on every page render, and so does every guard
     * on a platform page. Memoising a NEGATIVE answer is deliberate too — the alternative
     * re-queries for every non-operator on every request, which is almost every request.
     */
    public function operator(): ?PlatformOperator
    {
        if ($this->operatorResolved) {
            return $this->operatorRecord;
        }

        $this->operatorResolved = true;

        $subjectId = $this->actingSubjectId();

        if ($subjectId === null) {
            return $this->operatorRecord = null;
        }

        // Read in the PLATFORM ROOT's scope. Operators are not environment-owned, but the
        // subject they point at is, and the lookup crosses to it — under a tenant's
        // ambient scope the platform's own staff are simply not there.
        return $this->operatorRecord = app(PlatformRoot::class)->run(
            fn (): ?PlatformOperator => $this->operators->findBySubject($subjectId),
        );
    }

    /**
     * Whether ANYONE is signed in here.
     *
     * Separate from {@see isPlatformOperator()} because the two answers lead to different
     * refusals: somebody who is not signed in gets the sign-in page, which is a step they
     * can take, while somebody who IS signed in and lacks the authority gets a 404 — a 403
     * would confirm to any account holder that this deployment has a staff console at that
     * address.
     *
     * This used to ask three stores in turn, because there were three: a subject session,
     * an account-member session and an environment-admin one, for what is one person. That
     * arrangement produced the worst refusal of the three — an operator was sent to a
     * sign-in that wrote a MEMBER session, and a gate reading the SUBJECT session then
     * refused it and sent them back, forever. There is one session now. The environment
     * admin is still asked separately because its answer depends on the HOST as well as on
     * the session, and on a tenant host the subject session is not resolvable at all
     * ({@see EnvironmentAdminAuth}).
     */
    public function signedIn(): bool
    {
        return $this->subject->check() || $this->environmentAdmin->check();
    }

    /**
     * The subject acting on this request, or null when nobody is.
     *
     * An environment administrator is refused OUTRIGHT rather than looked up. They are
     * standing at a tenant's altitude on a tenant's host; if that same person really does
     * run the deployment they get the platform pages through their own sign-in, not as a
     * side effect of which console they last opened.
     */
    private function actingSubjectId(): ?string
    {
        if ($this->environmentAdmin->check()) {
            return null;
        }

        $subjectId = $this->subject->check() ? $this->subject->id() : '';

        return $subjectId === '' ? null : $subjectId;
    }

    /** @throws AuthorizationException */
    public function assertPlatformOperator(): void
    {
        if (! $this->isPlatformOperator()) {
            throw new AuthorizationException('You do not run this deployment.');
        }
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
