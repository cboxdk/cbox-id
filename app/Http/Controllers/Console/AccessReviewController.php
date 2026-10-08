<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Governance\AccessReviewFields;
use App\Actions\Governance\CloseAccessReview;
use App\Actions\Governance\DecideAccessReviewItem;
use App\Actions\Governance\OpenAccessReview;
use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Http\Requests\Console\OpenAccessReviewRequest;
use App\Http\Requests\Console\ReviewAccessItemRequest;
use App\Platform\Console\ConsolePlane;
use App\Platform\Help\HelpTopic;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Governance\Contracts\AccessReviews;
use Cbox\Id\Governance\Enums\AccessKind;
use Cbox\Id\Governance\Enums\CampaignStatus;
use Cbox\Id\Governance\Models\CertificationCampaign;
use Cbox\Id\Governance\Models\CertificationItem;
use Cbox\Id\Identity\Contracts\Subjects;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * CONSOLE › ACCESS REVIEWS — periodic certification. Opening one snapshots every direct
 * role assignment and membership in the organization as an item to certify or revoke;
 * closing it APPLIES every revoke recorded on it, and applies the review's own policy to
 * anything still un-reviewed (deny-by-default: revoke).
 *
 * EVERY READ AND EVERY WRITE IS FENCED TO THE ACTING ORGANIZATION, not merely to the
 * environment. That distinction is the whole security of this page: many organizations
 * share one environment, so an environment-scoped lookup is not a tenant boundary here. A
 * page that resolved a campaign on its primary key alone handed any organization's
 * administrator another's entire certification worklist — every reviewed subject's name,
 * email and role names — and {@see self::close()} then applied every revoke on it, which
 * makes the same id a cross-organization write that strips access inside somebody else's
 * tenant.
 *
 * The framework's own ownership argument could not catch that: `AccessReviews::close()`
 * takes an organization id, and what was fed to it was the CAMPAIGN's own — an ownership
 * check compared against itself, passing for every caller. It is the ACTING organization
 * that has to bound both the lookup and the write, which is what {@see self::writeOrganizationId()}
 * is for.
 *
 * STAFF REVIEWS are the one exception, and only on the environment console: a campaign
 * with no organization reviews every environment-wide (staff) grant, and a decision on it
 * takes the grant back in every organization at once. The organization console never
 * reaches one — its queries are bound to the organization in the WHERE clause, and a null
 * organization is never equal to anything — so a tenant's reviewer can neither see nor
 * revoke the vendor's staff grants.
 */
final readonly class AccessReviewController extends ConsoleController
{
    /** A screenful of the snapshot. See {@see self::show()} for why this is bounded. */
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $this->scope->assertMayAdminister();

        $filter = $this->organizationFilter();

        /*
         * The member's own organization's campaigns, or — on the environment console — the
         * one organization the list is filtered to. Unfiltered, every campaign in the
         * environment, a deliberate cross-tenant overview rather than a leak: the model's
         * environment scope still bounds it, and an organization member can never reach
         * that branch because their organization is implicit.
         */
        $query = $filter->apply($this->fenced(CertificationCampaign::query()))->orderByDesc('created_at');

        $term = trim($request->string('q')->toString());

        if ($term !== '') {
            $query->where('name', 'like', '%'.$term.'%');
        }

        $campaigns = $query->get();

        $owners = $this->scope->organizationNames($campaigns->pluck('organization_id'));

        return $this->page('console/access-reviews/index', 'Access reviews', [
            'help' => HelpProps::for(HelpTopic::AccessReviews),
            'reviews' => $campaigns->map(fn (CertificationCampaign $campaign): array => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                /*
                 * WHEN IT IS DUE, not when it was opened. A reviewer's question about a
                 * list of open reviews is which one is running out of time; "opened 3
                 * weeks ago" is the same fact stated so it cannot be acted on.
                 */
                'dueAt' => $campaign->due_at?->diffForHumans(),
                'overdue' => $campaign->status === CampaignStatus::Open
                    && $campaign->due_at !== null
                    && $campaign->due_at->isPast(),
                'open' => $campaign->status === CampaignStatus::Open,
                // A review of staff roles rather than of one organization's access.
                'staff' => $campaign->organization_id === null,
                // Whose access it reviews, on the list that holds every organization's.
                'organization' => $filter->active() || $campaign->organization_id === null
                    ? null
                    : ($owners[$campaign->organization_id] ?? $campaign->organization_id),
                'href' => $this->url('governance.show', $campaign->id),
            ])->all(),
            'search' => $term,
            'organizationFilter' => $this->organizationFilterProps($filter),
            'createHref' => $this->createUrl('governance.create'),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->scope->assertMayAdminister();

        $staff = $this->reviewsStaff();

        return $this->page('console/access-reviews/create', 'New access review', [
            // "For which organization?" — a review snapshots ONE organization's access, so the
            // environment console asks; prefilled and locked when opened from an organization.
            'organization' => $this->organizationPicker(),
            // The environment console can also review staff roles, which belong to no
            // organization. The Staff page links here with `?review=staff`.
            'canReviewStaff' => $staff,
            'covers' => $staff && $request->string('review')->toString() === 'staff' ? 'staff' : 'organization',
            'indexHref' => $this->url('governance'),
            'storeHref' => $this->url('governance.store'),
        ]);
    }

    public function store(OpenAccessReviewRequest $request, AccessReviews $reviews): RedirectResponse
    {
        // The action decides the same way for the API; these refusals stay here so a person
        // gets the page and the wording they always did.
        $this->scope->assertMayAdminister();

        // STAFF ROLES: the environment's own review, with no organization. Only on the
        // environment console — the organization console has no such choice, and a posted
        // `covers=staff` there is refused rather than quietly read as its own organization.
        if ($request->coversStaff()) {
            abort_unless($this->reviewsStaff(), 403);

            $organizationId = null;
        } else {
            /*
             * The organization plane's own, whatever was posted; on the environment console
             * the form's "For which organization?", checked against this environment. A
             * review snapshots one organization's access, so "none" is a field error.
             */
            $organizationId = $this->chosenOrganizationId($request);
        }

        // Who opened it is the scope's `actorId()`, as it always was — the action's principal.
        $result = $this->act(OpenAccessReview::class, [
            'name' => $request->name(),
            'covers' => $organizationId === null ? 'staff' : 'organization',
            'organization_id' => $organizationId,
        ], fallback: 'name');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var CertificationCampaign $campaign */
        $campaign = $result->value;

        return to_route($this->scope->routeName('governance.show'), $campaign->id)
            ->with('status', 'Access review "'.$campaign->name.'" opened with '
                .$reviews->countItemsFor($campaign->id).' item(s).');
    }

    public function show(string $campaign, AccessReviews $reviews, Subjects $subjects): Response
    {
        $this->scope->assertMayAdminister();

        $model = $this->visible($campaign);

        /*
         * A PAGE OF THE SNAPSHOT. `itemsFor()` reads one row per role assignment plus one
         * per membership in the organization — a set that grows with the customer's
         * end-user count. Under Volt this was a `with()`, so it re-ran in full after every
         * single certify and revoke: a review of a twenty-thousand-person organization was
         * twenty thousand rows hydrated per click.
         */
        $items = $reviews->paginateItemsFor($model->id, self::PER_PAGE);

        $open = $model->status === CampaignStatus::Open;

        /** @var list<CertificationItem> $rows */
        $rows = array_values($items->items());

        $people = $this->subjectLabels($rows, $subjects);
        $roles = $this->roleNames($rows);

        return $this->page('console/access-reviews/show', $model->name, [
            'review' => [
                'id' => $model->id,
                'name' => $model->name,
                'open' => $open,
                'staff' => $model->organization_id === null,
            ],
            'items' => array_map(fn (CertificationItem $item): array => [
                'id' => $item->id,
                // A reviewer certifying access needs to see WHO they are deciding on and
                // WHAT, so ids are resolved to names here — the table never shows a bare
                // ULID and asks somebody to certify it.
                'subject' => $people[$item->subject_id] ?? null,
                'subjectId' => $item->subject_id,
                'kind' => match ($item->access_type) {
                    AccessKind::Role => 'Role',
                    AccessKind::Membership => 'Membership',
                    AccessKind::EnvironmentRole => 'Staff role',
                },
                'access' => $roles[$item->access_ref] ?? $item->access_ref,
                'decision' => $item->decision->value,
                // A revoke that could not be applied at close, and why. Silence here would
                // report a review as done when part of it did not take.
                'applied' => $item->applied,
                'note' => $item->application_note,
                'reviewHref' => $this->url('governance.item', ['campaign' => $model->id, 'item' => $item->id]),
            ], $rows),
            'pagination' => PaginationProps::from($items),
            'indexHref' => $this->url('governance'),
            'closeHref' => $this->url('governance.close', $model->id),
        ]);
    }

    /**
     * Certify or revoke one item.
     *
     * AN EXPLICIT DECISION rather than two endpoints, because the two are one act with two
     * answers and a reviewer moves between them — somebody who mis-clicked Revoke has to
     * be able to say Certify without the page having a different opinion about which
     * button it drew.
     */
    public function item(ReviewAccessItemRequest $request, string $campaign, string $item): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        // Resolved so a forged campaign id cannot reach an item through this route, even
        // though the contract also fences the item on the organization below.
        $model = $this->visible($campaign);

        // The action records the reviewer as the person (`actorId()`) and checks the item
        // against the organization THIS console administers, never the campaign's own.
        $result = $this->act(DecideAccessReviewItem::class, [
            'id' => $model->id,
            'item_id' => $item,
            'decision' => $request->certifies() ? 'certified' : 'revoked',
            'organization_id' => $this->writeOrganizationId($model),
        ], fallback: 'decision');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        return back()->with('status', $request->certifies() ? 'Access certified.' : 'Access revoked.');
    }

    /**
     * Close the campaign, applying every revoke recorded on it.
     *
     * Guarded to OPEN campaigns: closing a closed one would re-apply decisions that have
     * already taken effect, against a roster that has moved on since.
     */
    public function close(string $campaign): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $model = $this->visible($campaign);

        if ($model->status !== CampaignStatus::Open) {
            return back();
        }

        $result = $this->act(CloseAccessReview::class, [
            'id' => $model->id,
            'organization_id' => $this->writeOrganizationId($model),
        ]);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        return back()->with('status', 'Access review closed — revoked access was applied.');
    }

    /**
     * The campaign this page acts on, or a 404.
     *
     * Fenced to the member's own organization on the organization console. On the
     * environment console the whole environment resolves, which is the overview the list
     * already shows.
     */
    private function visible(string $campaign): CertificationCampaign
    {
        $model = $this->fenced(CertificationCampaign::query())->whereKey($campaign)->first();

        abort_if($model === null, 404);

        return $model;
    }

    /**
     * The campaigns this console may see, bound in the WHERE clause.
     *
     * The organization console: its own organization's, and nothing else — `organization_id
     * = ?` never matches a staff review, whose organization is null. The environment
     * console: everything, its staff reviews included — the list narrows itself with the
     * Organization filter ({@see self::index()}).
     *
     * @param  Builder<CertificationCampaign>  $query
     * @return Builder<CertificationCampaign>
     */
    private function fenced(Builder $query): Builder
    {
        $organizationId = $this->scope->organizationId();

        if ($organizationId === null) {
            return $query;
        }

        if (! $this->reviewsStaff()) {
            return $query->where('organization_id', $organizationId);
        }

        return $query->where(fn (Builder $q): Builder => $q
            ->where('organization_id', $organizationId)
            ->orWhereNull('organization_id'));
    }

    /** Whether this console may review staff roles: the environment console only. */
    private function reviewsStaff(): bool
    {
        return $this->scope->plane() === ConsolePlane::Environment;
    }

    /**
     * The organization a write is attributed to and checked against.
     *
     * The CALLER's, NEVER the campaign's own `organization_id`. Reading the id off the record
     * being written is what made the framework's ownership assertion vacuous — it compared
     * the campaign to itself and passed for every caller. On the organization console this
     * is the member's own organization, so the assertion compares the record to the
     * ADMINISTRATOR, which is the question it was written to answer.
     *
     * On the environment console it is null — that console's administrator holds every
     * organization here — and the action acts on the review's own organization, which that
     * authority covers ({@see AccessReviewFields::writeOrganizationId()}).
     */
    private function writeOrganizationId(CertificationCampaign $campaign): ?string
    {
        /*
         * A STAFF REVIEW is written as the environment's (null), and only from the
         * environment console — the console is what authorizes the null, never the
         * campaign. The organization console cannot have resolved a staff review in the
         * first place ({@see fenced()}); the plane check is the second statement of it.
         */
        if ($campaign->organization_id === null && $this->reviewsStaff()) {
            return null;
        }

        return $this->routeOrganizationId();
    }

    /**
     * Subject id => a name a reviewer can act on.
     *
     * ONE QUERY. `find()` inside the loop was a round trip per distinct person, and a
     * campaign holds one item per role each of them holds — so a reviewer working through
     * a large organization paid for the whole roster again on every decision they made.
     *
     * @param  list<CertificationItem>  $items
     * @return array<string, string>
     */
    private function subjectLabels(array $items, Subjects $subjects): array
    {
        $ids = [];

        foreach ($items as $item) {
            if ($item->subject_id !== '') {
                $ids[$item->subject_id] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        $labels = [];

        foreach ($subjects->findMany(array_keys($ids)) as $subject) {
            $name = $subject->name ?? $subject->email;

            if (is_string($name) && $name !== '') {
                $labels[$subject->id] = $name;
            }
        }

        return $labels;
    }

    /**
     * For a role item the `access_ref` is a role id — mapped to the role's name, because
     * "certify this ULID" is not a question anybody can answer.
     *
     * @param  list<CertificationItem>  $items
     * @return array<string, string>
     */
    private function roleNames(array $items): array
    {
        $roleIds = [];

        foreach ($items as $item) {
            if (in_array($item->access_type, [AccessKind::Role, AccessKind::EnvironmentRole], true) && $item->access_ref !== '') {
                $roleIds[$item->access_ref] = true;
            }
        }

        if ($roleIds === []) {
            return [];
        }

        $names = [];

        foreach (Role::query()->whereIn('id', array_keys($roleIds))->get(['id', 'name']) as $role) {
            $names[$role->id] = $role->name;
        }

        return $names;
    }
}
