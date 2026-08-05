<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\AccountAuth;
use App\Platform\AccountCapabilities;
use App\Platform\CurrentUser;
use App\Platform\Entitlements;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\EnvironmentSudo;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\DatabaseAccountMembers;
use Cbox\Id\Platform\Enums\AccountRole;
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

    /** How many organizations the switcher offers at once before asking for a search. */
    public const SWITCHER_LIMIT = 8;

    private bool $operatorResolved = false;

    private ?PlatformOperator $operatorRecord = null;

    /** @var array<string, string>|null memo for {@see availableOrganizations()} */
    private ?array $availableRecord = null;

    private bool $nameResolved = false;

    private ?string $nameRecord = null;

    /** @see validatedThisRequest() — "(selection, environment)" the verdict below belongs to. */
    private ?string $validatedKey = null;

    private bool $validatedRecord = false;

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
        //
        // Asked as an EXISTENCE question about the one id, rather than by loading every
        // organization in the environment and looking for it in the map. The property is
        // identical — the same global scope answers both — but the map version made the
        // cost of validating one id the size of the tenant: a console page asks this ten
        // times through entitled(), and an environment holding a few thousand B2B
        // organizations paid ten full reads of them per render.
        return $this->validatedThisRequest($chosen) ? $chosen : null;
    }

    /**
     * The re-validation above, asked once per (selection, environment) rather than ten
     * times per render.
     *
     * MEMOISED ON ITS INPUTS, not merely computed once, and the environment is one of
     * them. That is the whole of the property this re-validation exists for: the answer is
     * "does this id name an organization on THIS host", and a request can legitimately
     * move between environments ({@see EnvironmentContext::runAs()}) — a flat memo would
     * carry the previous environment's verdict across that move, which is precisely the
     * bleed the check is here to stop.
     */
    private function validatedThisRequest(string $organizationId): bool
    {
        $key = $organizationId."\0".(app(EnvironmentContext::class)->current()?->environmentKey() ?? '');

        if ($this->validatedKey === $key) {
            return $this->validatedRecord;
        }

        $this->validatedRecord = $this->existsHere($organizationId);
        $this->validatedKey = $key;

        return $this->validatedRecord;
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

        if (! $this->existsHere($organizationId)) {
            throw new AuthorizationException('That organization is not in this environment.');
        }

        session()->put(self::SELECTION_KEY, $organizationId);

        // The step-up does not travel with the selection.
        //
        // {@see \App\Platform\PlatformAuth::switchOrganization()} drops it on the tenant
        // plane and says why: switching tenant changes which authority the session
        // carries, so a confirmation made against the previous one does not transfer. The
        // same sentence is truer here, because this plane's confirmation is worth more —
        // one password, entered once, otherwise covered rotating EVERY tenant's secrets
        // for the rest of the 15-minute window, simply by switching between them.
        app(EnvironmentSudo::class)->forget();

        // The selection has moved, so anything derived from it has to. Nothing here can
        // have changed the SET — but a memo that survives the write it belongs to is the
        // bug memoising would otherwise introduce, and the name definitely has changed.
        $this->availableRecord = null;
        $this->nameResolved = false;
        $this->nameRecord = null;
    }

    /**
     * Whether an organization id names an organization IN THIS ENVIRONMENT.
     *
     * The environment scope on {@see Organization} does the work: the model's global scope
     * filters by the host-resolved environment, so an id belonging to another one simply
     * is not found. That is the property both callers above need, and it is the reason
     * neither has to compare against a list it built itself.
     */
    private function existsHere(string $organizationId): bool
    {
        return $organizationId !== ''
            && Organization::query()->whereKey($organizationId)->exists();
    }

    /**
     * The organizations this administrator may act on, id => name.
     *
     * A plain map rather than a Collection: this is a label lookup — a serialization edge
     * — and `pluck()` returns a shape neither PHPStan nor a reader can pin down, which is
     * how a key silently becomes an int.
     *
     * FOR LABELLING ROWS THE PAGE HAS ALREADY LOADED, not for offering a choice. The
     * switcher used to render this whole map as an option list and stopped doing so —
     * see {@see searchOrganizations()} — because on the environment plane it is
     * unbounded, and "every organization in the tenant" is not a size a page can be.
     *
     * Memoised per request, the same way {@see operator()} is and for the same reason:
     * several list components ask on one render, and the answer cannot change under them.
     *
     * @return array<string, string>
     */
    public function availableOrganizations(): array
    {
        if ($this->availableRecord !== null) {
            return $this->availableRecord;
        }

        if ($this->plane() === ConsolePlane::Organization) {
            $organization = $this->subject->organization();

            return $this->availableRecord = $organization === null
                ? []
                : [$organization->id => $organization->name];
        }

        $available = [];

        // Environment-scoped by the model's own global scope, so this is every
        // organization in THIS environment and never another's.
        foreach (Organization::query()->orderBy('name')->get(['id', 'name']) as $organization) {
            $available[$organization->id] = $organization->name;
        }

        return $this->availableRecord = $available;
    }

    /**
     * The names of ONLY the organizations a page actually names.
     *
     * {@see availableOrganizations()} answers a different question — "which may this
     * person switch to" — and answering it is unavoidably the size of the environment.
     * Four list pages were calling it to label at most 25 paginated rows, so a tenant
     * with a few thousand organizations paid a full hydration of every one of them on
     * every render AND on every Livewire action. The query count and the HTML never
     * changed, which is exactly why the console's query-budget test could not see it;
     * the cost is hydration, and it grows with the customer's own success.
     *
     * `console/directories/index` already did it this way. This lifts that shape to the
     * seam so the bounded form is the one that is easy to reach for.
     *
     * @param  iterable<mixed>  $organizationIds  ids from the rows being rendered; nulls
     *                                            and duplicates are fine
     * @return array<string, string>
     */
    public function organizationNames(iterable $organizationIds): array
    {
        $ids = [];

        foreach ($organizationIds as $id) {
            if (is_string($id) && $id !== '') {
                $ids[$id] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        // Environment-scoped by the model's own global scope, exactly as the unbounded
        // read was — an id from another environment resolves to nothing rather than to a
        // name this person may not see.
        /** @var array<string, string> */
        return Organization::query()
            ->whereIn('id', array_keys($ids))
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * The organizations to OFFER, for a term the administrator typed — a bounded page of
     * the same set {@see availableOrganizations()} describes.
     *
     * This exists because the set has no size limit. The switcher rendered every
     * organization in the environment, each as its own `<form>` with its own CSRF token,
     * so an environment holding a few thousand of them — the shape this product is sold
     * into — served a 3.5 MB document on every console page. That is an availability
     * problem, not a slow one, and no amount of caching fixes a response that large.
     *
     * The organization plane has exactly one and never chooses, so it answers with that
     * one and ignores the term: the switcher is a label there, not a control.
     *
     * @return list<ConsoleOrganization>
     */
    public function searchOrganizations(string $term = '', int $limit = self::SWITCHER_LIMIT): array
    {
        if ($this->plane() === ConsolePlane::Organization) {
            $organization = $this->subject->organization();

            return $organization === null ? [] : [new ConsoleOrganization($organization->id, $organization->name)];
        }

        $term = trim($term);

        $query = Organization::query()->orderBy('name');

        if ($term !== '') {
            // Escaped, because `%` and `_` in a typed term would otherwise be wildcards —
            // harmless here, but a search box that quietly means something else is a
            // search box people stop trusting.
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);

            $query->where('name', 'like', '%'.$escaped.'%');
        }

        return array_values(
            $query->limit(max(1, $limit))
                ->get(['id', 'name'])
                ->map(fn (Organization $organization): ConsoleOrganization => new ConsoleOrganization(
                    $organization->id,
                    $organization->name,
                ))
                ->all()
        );
    }

    /** How many organizations this administrator may act on, in total. */
    public function organizationCount(): int
    {
        return $this->plane() === ConsolePlane::Organization
            ? ($this->subject->organization() === null ? 0 : 1)
            : Organization::query()->count();
    }

    /**
     * The acting organization's name, or null when none is chosen.
     *
     * Asked of the ONE organization rather than looked up in
     * {@see availableOrganizations()}, which is the whole point: rendering the chosen
     * organization's name in the chrome must not cost a read of every organization in the
     * environment. Memoised because the chrome asks and so does the page eyebrow.
     */
    public function organizationName(): ?string
    {
        if ($this->nameResolved) {
            return $this->nameRecord;
        }

        $this->nameResolved = true;

        $organizationId = $this->organizationId();

        if ($organizationId === null) {
            return $this->nameRecord = null;
        }

        $name = Organization::query()->whereKey($organizationId)->value('name');

        return $this->nameRecord = is_string($name) ? $name : null;
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

    /**
     * What the acting person holds on the organization they are administering, WHEN that
     * organization owns identity providers — null otherwise.
     *
     * This is the predicate behind the console's IdP-ownership area, and it is asked here
     * rather than of {@see AccountAuth} directly for the reason every other question on
     * this class is: "who is acting, on which organization, and what may they do" has one
     * answer per request, and the layout, the nav features and each page's own guard would
     * otherwise be three places that could disagree about it.
     *
     * TWO conditions, not one. Holding an account role is not enough — the acting
     * ORGANIZATION has to be the one that account owns. On `acme.cboxid.com` the acting
     * organization is a tenant of somebody else's IdP, so this answers null and the area is
     * simply absent; the same is true on the root the moment an operator switches to an
     * organization that is not their own. Without the second condition an account owner who
     * switched organizations would be offered their own projects and billing while the
     * chrome named a different tenant.
     *
     * AN UNHOMED ACCOUNT ANSWERS NULL, and the exception that used to be here is worth
     * naming so it is not re-added. It read: when the account has no organization there is
     * nothing to compare against, so return the role. Its premise was true — every account
     * created before `accounts.organization_id` existed was unhomed, and the local dev
     * database still held two — and its stated defence was that nothing is loosened
     * because every page in the area reads by `account_id`.
     *
     * That defence answers the wrong question. It is about which DATA the pages show; the
     * two-condition rule is about where the area APPEARS. Returning the role with no
     * comparison at all put the whole identity-platform area in the rail on every
     * organization the person could act on, tenant hosts included — precisely the "offered
     * their own projects and billing while the chrome named a different tenant" the
     * paragraph above exists to prevent. It is the same collapse
     * {@see self::organizationId()} argues against: "we do not know which organization this
     * account owns" became "all of them".
     *
     * The premise is now gone rather than merely overruled. `2026_08_05_000200` in the
     * framework homes every account that predates the column, and no reachable path
     * creates a new unhomed one — the installer stamps the platform root inside the same
     * transaction, BEFORE it provisions the first account, and signup only ever runs on a
     * deployment that is already installed. So an account with no organization is a broken
     * row, not a shape to accommodate, and the honest answer to a broken row is the closed
     * one: the area is hidden, not offered everywhere.
     *
     * `AccountRole` is still the predicate rather than {@see MembershipRole},
     * because the account member row is still where account capabilities live: the
     * membership that places a member in the account's organization carries a NEUTRAL role
     * on purpose ({@see DatabaseAccountMembers::attachSubject()}), so
     * asking the membership would answer "member" for the account's owner.
     *
     * Resolved through the container rather than the constructor, like the operator and
     * environment lookups above: this class is constructed directly by the tests that
     * assert the rail's invariants, and a fourth constructor dependency buys nothing here.
     */
    public function accountRole(): ?AccountRole
    {
        $member = app(AccountAuth::class)->current();

        if ($member === null) {
            return null;
        }

        $accountOrganizationId = $member->account?->organization_id;

        if (! is_string($accountOrganizationId) || $accountOrganizationId === '') {
            return null;
        }

        $organizationId = $this->plane() === ConsolePlane::Organization
            ? $this->subject->organizationId()
            : $this->organizationId();

        return $accountOrganizationId === $organizationId ? $member->role : null;
    }

    /**
     * What the acting person MAY DO on the account they are administering — null when
     * they are administering somebody else's organization, or no account at all.
     *
     * The capability question, separated from the role question that {@see AccountRole()}
     * answers. Every guard, nav entry and feature closure asks this one; `accountRole()`
     * remains for the two places that need the VALUE — the role stamped on a machine
     * credential, and the role rendered next to a person's name.
     *
     * The separation is the point of the seam. While the account plane owned its own role
     * column the two questions had the same answer, so thirty call sites asked the enum
     * directly and nothing was lost. Once an account IS an organization they stop being
     * the same question: the capability has to be derived from a membership plus a
     * refinement the organization plane cannot express, and a call site that asked the
     * enum would keep answering from the old column without ever going red.
     */
    public function capabilities(): ?AccountCapabilities
    {
        if ($this->accountRole() === null) {
            return null;
        }

        // THE MEMBERSHIP, not the member row — this is the flip.
        //
        // `accountRole()` above answers a different question and is still the right one to
        // ask first: does the acting organization belong to an account, and is it the one
        // this person's account owns. What it must no longer answer is what they may DO
        // there. An account IS an organization; a person's authority over an organization
        // is their membership of it; and keeping a second answer on the account member row
        // is the drift this whole fold exists to remove.
        //
        // The two agree today by construction — `AccountRole::asMembershipRole()` is the
        // one mapping and `DatabaseAccountMembers::setRole()` carries every change onto
        // the membership — which is exactly what makes reading the membership safe rather
        // than a behaviour change. The role/page matrix in `IdentityPlatformConsoleTest`
        // is the assertion that it stayed a non-change.
        //
        // On the ENVIRONMENT plane there is no subject session and therefore no membership
        // to read, so the member row's own role is mapped through the same definition. That
        // path is an environment administrator acting via the handoff, and it folds with
        // the rest of the identity work rather than here.
        $role = $this->plane() === ConsolePlane::Organization
            ? $this->subject->role()
            : app(AccountAuth::class)->current()?->role->asMembershipRole();

        return $role === null ? null : AccountCapabilities::of($role);
    }

    /** Whether the organization being administered owns identity providers of its own. */
    public function ownsIdentityProviders(): bool
    {
        return $this->accountRole() !== null;
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
