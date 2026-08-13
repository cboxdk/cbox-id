<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Governance\Contracts\AccessReviews;
use Cbox\Id\Governance\Enums\AccessKind;
use Cbox\Id\Governance\Enums\CampaignStatus;
use Cbox\Id\Governance\Models\CertificationCampaign;
use Cbox\Id\Identity\Contracts\Subjects;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Environment control plane › Access reviews › detail. The full, deep-linkable
 * worklist for one certification campaign: every snapshotted role assignment and
 * membership, each certified or revoked, and the close action that applies revokes.
 *
 * Every read/write re-resolves the campaign within THIS environment (the
 * CertificationCampaign model's BelongsToEnvironment scope) AND within the acting
 * organization, then 404s — see {@see self::resolve()}. The acting reviewer is the
 * env-admin account member, resolved from session.
 */
new #[Layout('components.layouts.console', ['title' => 'Access review'])] class extends Component
{
    use WithPagination;

    /** A screenful of the snapshot. See the read in with() for why this is bounded. */
    private const PER_PAGE = 25;

    /**
     * Second layer. The route's `env.admin` middleware is the primary gate and IS
     * re-run on Livewire actions (PersistentMiddlewareTest holds that), but this
     * console previously had NO in-component authorization at all — so when that
     * middleware was missing from the persistent list, every action here answered
     * unauthenticated. boot() rather than mount(): only boot() runs on each action.
     */
    public function boot(): void
    {
        app(ConsoleScope::class)->assertMayAdminister();
    }

    public string $campaignId = '';

    public function mount(string $campaign): void
    {
        $this->campaignId = $this->resolve($campaign)->id;
    }

    private function campaign(): CertificationCampaign
    {
        return $this->resolve($this->campaignId);
    }

    /**
     * The campaign this page acts on, or 404.
     *
     * Fenced to the acting organization, not merely to the environment. This page
     * resolved on the primary key alone, and the environment scope is NOT a tenant
     * boundary here — many organizations share one environment — so an Admin of any
     * organization could deep-link another's campaign id and get the whole certification
     * worklist: every reviewed subject's name, email and role names. `close()` then
     * APPLIES every revoke on it, which made the same id a cross-organization write that
     * strips access inside somebody else's tenant.
     *
     * The ownership argument the framework offers ({@see AccessReviews::close()} takes an
     * organization id) could not catch it either, because the campaign's OWN
     * `organization_id` was what got fed back in — an ownership check compared against
     * itself. It is the ACTING organization that has to bound the lookup, which is what
     * every sibling in this console already does ({@see ConsoleScope}).
     *
     * With no organization chosen — only reachable by an environment administrator —
     * the whole environment resolves, which is the overview the list already shows.
     */
    private function resolve(string $id): CertificationCampaign
    {
        $actingOrganizationId = app(ConsoleScope::class)->organizationId();

        $model = CertificationCampaign::query()
            ->whereKey($id)
            ->when($actingOrganizationId !== null, fn ($q) => $q->where('organization_id', $actingOrganizationId))
            ->first();

        abort_if($model === null, 404);

        return $model;
    }

    public function certify(string $itemId, AccessReviews $reviews): void
    {
        $reviews->certify($itemId, $this->reviewerId(), $this->writeOrganizationId());
    }

    public function revoke(string $itemId, AccessReviews $reviews): void
    {
        $reviews->revoke($itemId, $this->reviewerId(), $this->writeOrganizationId());
    }

    /**
     * Close this campaign, applying every revoked item. Guarded to Open campaigns; a
     * closed campaign is left untouched.
     */
    public function close(AccessReviews $reviews): void
    {
        $campaign = $this->campaign();

        if ($campaign->status !== CampaignStatus::Open) {
            return;
        }

        $reviews->close($campaign->id, $this->writeOrganizationId());
        $this->dispatch('toast', message: 'Access review closed — revoked access was applied.');
    }

    /**
     * The organization a write is attributed to and checked against.
     *
     * `requireOrganizationId()`, never `$campaign->organization_id`. Reading the id off
     * the record being written is what made the framework's ownership assertion vacuous:
     * it compared the campaign to itself and passed for every caller. Asking the scope
     * instead means the assertion compares the record to the ADMINISTRATOR, which is the
     * question it was written to answer — and an environment administrator who has chosen
     * no organization is refused rather than allowed to apply revokes environment-wide.
     * No new burden: opening a review already requires a chosen organization (see
     * governance/create), so a campaign that exists was named against one.
     */
    private function writeOrganizationId(): string
    {
        return app(ConsoleScope::class)->requireOrganizationId();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(AccessReviews $reviews): array
    {
        $campaign = $this->campaign();

        // A PAGE OF THE SNAPSHOT. `itemsFor()` reads one row per role assignment plus one
        // per membership in the organization — a set that grows with the customer's
        // end-user count — and this is a `with()`, so it re-ran in full after every single
        // certify and revoke. A review of a twenty-thousand-person organization was
        // twenty thousand rows hydrated per click.
        $items = $reviews->paginateItemsFor($campaign->id, self::PER_PAGE);

        return [
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            'campaign' => $campaign,
            'items' => $items,
            // A reviewer certifying access needs to see *who* they're deciding on and
            // *what* — resolve subject ids to names/emails and role refs to role names
            // so the table never shows bare ULIDs.
            'subjectLabels' => $this->resolveSubjects($items->items()),
            'roleNames' => $this->resolveRoleNames($items->items()),
        ];
    }

    /**
     * @param  iterable<int, object{subject_id: string}>  $items
     * @return array<string, string>
     */
    private function resolveSubjects(iterable $items): array
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

        // ONE QUERY. `find()` inside the loop was a round trip per distinct person in the
        // campaign, and a campaign has one item per role each of them holds — so a
        // reviewer working through a large organization paid for the whole roster again
        // on every decision they made.
        $labels = [];

        foreach (app(Subjects::class)->findMany(array_keys($ids)) as $subject) {
            $name = $subject->name ?? $subject->email;

            if (is_string($name) && $name !== '') {
                $labels[$subject->id] = $name;
            }
        }

        return $labels;
    }

    /**
     * For role items the `access_ref` is a role id — map those to role names.
     *
     * @param  iterable<int, object{access_type: AccessKind, access_ref: string}>  $items
     * @return array<string, string>
     */
    private function resolveRoleNames(iterable $items): array
    {
        $roleIds = [];

        foreach ($items as $item) {
            if ($item->access_type === AccessKind::Role && $item->access_ref !== '') {
                $roleIds[$item->access_ref] = true;
            }
        }

        if ($roleIds === []) {
            return [];
        }

        /** @var array<string, string> $names */
        $names = Role::query()
            ->whereIn('id', array_keys($roleIds))
            ->pluck('name', 'id')
            ->all();

        return $names;
    }

    /** The acting reviewer: the env-admin account member for this environment. */
    /** @see ConsoleScope::actorId() — the subject, on either plane, so the trail reads. */
    private function reviewerId(): string
    {
        return app(ConsoleScope::class)->actorId();
    }
}; ?>

<div class="space-y-6">
    <div>
        <a href="{{ route($scopeRoute('governance')) }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Access reviews</a>
        <div class="mt-2 flex items-center gap-3 flex-wrap">
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">{{ $campaign->name }}</h1>
            @if ($campaign->status === \Cbox\Id\Governance\Enums\CampaignStatus::Open)
                <span class="badge badge-warn">Open</span>
            @else
                <span class="badge badge-success">Closed</span>
            @endif
        </div>
        <p class="mt-1 text-sm mono" style="color:var(--faint)">{{ $campaign->id }}</p>
    </div>

    {{-- Items worklist --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <div class="flex items-center justify-between gap-3">
            <h2 class="cbx-section-title">Items</h2>
            @if ($campaign->status === \Cbox\Id\Governance\Enums\CampaignStatus::Open)
                <x-confirm-delete
                    :name="$campaign->name"
                    action="close"
                    label="Close &amp; apply"
                    verb="Close and apply"
                    trigger-class="btn btn-ghost btn-sm shrink-0"
                    trigger-style="color:var(--destructive)"
                    consequence="Every revoke recorded on this review is applied for real now, and anything still un-reviewed follows the review's policy — which defaults to revoke. This cannot be undone." />
            @endif
        </div>
        <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
            @forelse ($items as $item)
                <div class="flex flex-wrap items-center gap-3 p-4 {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)" wire:key="item-{{ $item->id }}">
                    <div class="min-w-0 flex-1">
                        @if ($label = ($subjectLabels[$item->subject_id] ?? null))
                            <span class="font-medium truncate">{{ $label }}</span>
                        @else
                            <span class="font-medium truncate mono" style="color:var(--muted)">{{ \Illuminate\Support\Str::limit($item->subject_id, 16) }}</span>
                        @endif
                        <p class="text-sm truncate" style="color:var(--muted)">
                            <span class="badge">{{ ucfirst($item->access_type->value) }}</span>
                            {{ $roleNames[$item->access_ref] ?? $item->access_ref }}
                        </p>
                    </div>

                    @if ($item->decision === \Cbox\Id\Governance\Enums\ReviewDecision::Certified)
                        <span class="badge badge-success">Certified</span>
                    @elseif ($item->decision === \Cbox\Id\Governance\Enums\ReviewDecision::Revoked)
                        <span class="badge badge-danger">Revoked</span>
                    @else
                        <span class="badge">Pending</span>
                    @endif

                    @if ($campaign->status === \Cbox\Id\Governance\Enums\CampaignStatus::Open)
                        <div class="flex items-center gap-2 shrink-0">
                            <button type="button" wire:click="certify('{{ $item->id }}')" class="btn btn-ghost btn-sm"
                                    wire:loading.attr="disabled" wire:target="certify('{{ $item->id }}')">Certify</button>
                            {{-- Revoke sat ~8px from Certify with no guard: a mis-click recorded a
                                 revoke against the wrong person. The message names WHO and WHAT. --}}
                            <button type="button" wire:click="revoke('{{ $item->id }}')" class="btn btn-ghost btn-sm" style="color:var(--destructive)"
                                    wire:loading.attr="disabled" wire:target="revoke('{{ $item->id }}')"
                                    wire:confirm="Revoke {{ $roleNames[$item->access_ref] ?? $item->access_ref }} from {{ $subjectLabels[$item->subject_id] ?? $item->subject_id }}?&#10;&#10;The revoke is recorded now and applied when this review closes.">Revoke</button>
                        </div>
                    @elseif (! $item->applied && $item->decision === \Cbox\Id\Governance\Enums\ReviewDecision::Revoked)
                        <span class="text-xs shrink-0" style="color:var(--destructive)" title="{{ $item->application_note }}">not applied</span>
                    @endif
                </div>
            @empty
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="roles" class="w-5 h-5" /></div>
                    <h3>No access in scope</h3>
                    <p>This organization has no direct role or membership grants to certify.</p>
                </div>
            @endforelse

            @if ($items->hasPages())
                <div class="mt-4">{{ $items->links() }}</div>
            @endif
        </div>
    </div>
</div>
