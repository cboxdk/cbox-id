<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Governance\Contracts\AccessReviews;
use Cbox\Id\Governance\Enums\AccessKind;
use Cbox\Id\Governance\Enums\CampaignStatus;
use Cbox\Id\Governance\Enums\ReviewDecision;
use Cbox\Id\Governance\Models\CertificationCampaign;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Models\Organization;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Environment control plane › Access reviews — periodic access certification.
 *
 * Campaigns, items and subjects are all environment-owned (BelongsToEnvironment), so
 * every query and every service call resolves ONLY within this environment; an id
 * minted in another plane never matches, closing cross-tenant tampering. Access is
 * gated by the env-admin session (route middleware), so the account member has full
 * CRUD here — there is no per-org entitlement lock at the control-plane level.
 *
 * The acting reviewer is the env-admin account member (a fourth-plane identity), not
 * a subject inside the environment; it is resolved from the env-admin session.
 */
new #[Layout('components.layouts.environment', ['title' => 'Access reviews'])] class extends Component
{
    #[Validate('required|string|max:190')]
    public string $name = '';

    #[Validate('required|string')]
    public string $organization_id = '';

    public bool $creating = false;

    public ?string $selected = null;

    /**
     * Open a campaign: snapshot the selected organization's direct role assignments
     * and memberships as pending items to certify or revoke.
     */
    public function open(AccessReviews $reviews): void
    {
        $this->validate();

        $campaign = $reviews->open(
            $this->organization_id,
            $this->name,
            now()->addWeek(),
            createdBy: $this->reviewerId(),
        );

        $this->reset('name', 'organization_id', 'creating');
        $this->selected = $campaign->id;
        session()->flash('status', 'Access review "'.$campaign->name.'" opened with '.count($reviews->itemsFor($campaign->id)).' item(s).');
    }

    public function select(string $id): void
    {
        $this->selected = $id;
    }

    public function certify(string $itemId, AccessReviews $reviews): void
    {
        $reviews->certify($itemId, $this->reviewerId());
    }

    public function revoke(string $itemId, AccessReviews $reviews): void
    {
        $reviews->revoke($itemId, $this->reviewerId());
    }

    /**
     * Close a campaign THIS environment owns, or refuse. The lookup is
     * environment-scoped, so an id from another plane resolves to null and is a 404
     * — never a cross-tenant close (deny-by-default), before any access is applied.
     */
    public function close(string $id, AccessReviews $reviews): void
    {
        $campaign = CertificationCampaign::query()->whereKey($id)->first();

        abort_if($campaign === null, 404);

        $reviews->close($campaign->id);
        session()->flash('status', 'Access review closed — revoked access was applied.');
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $reviews = app(AccessReviews::class);
        $campaign = $this->selected !== null
            ? CertificationCampaign::query()->whereKey($this->selected)->first()
            : null;

        $items = $campaign !== null ? $reviews->itemsFor($campaign->id) : [];

        return [
            'campaigns' => CertificationCampaign::query()
                ->orderByDesc('created_at')
                ->get(),
            'campaign' => $campaign,
            'items' => $items,
            'organizations' => Organization::query()->orderBy('name')->get(),
            // A reviewer certifying access needs to see *who* they're deciding on and
            // *what* — resolve subject ids to names/emails and role refs to role names
            // so the table never shows bare ULIDs.
            'subjectLabels' => $this->resolveSubjects($items),
            'roleNames' => $this->resolveRoleNames($items),
        ];
    }

    /**
     * @param  iterable<int, object{subject_id: string}>  $items
     * @return array<string, string>
     */
    private function resolveSubjects(iterable $items): array
    {
        $subjects = app(Subjects::class);
        $labels = [];

        foreach ($items as $item) {
            $id = $item->subject_id;

            if (! is_string($id) || $id === '' || isset($labels[$id])) {
                continue;
            }

            $subject = $subjects->find($id);
            $name = $subject?->name ?? $subject?->email;

            if (is_string($name) && $name !== '') {
                $labels[$id] = $name;
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
            if ($item->access_type === AccessKind::Role && is_string($item->access_ref) && $item->access_ref !== '') {
                $roleIds[$item->access_ref] = true;
            }
        }

        if ($roleIds === []) {
            return [];
        }

        return Role::query()
            ->whereIn('id', array_keys($roleIds))
            ->pluck('name', 'id')
            ->all();
    }

    /** The acting reviewer: the env-admin account member for this environment. */
    private function reviewerId(): string
    {
        return app(EnvironmentAdminAuth::class)->current()?->id ?? '';
    }
}; ?>

<div>
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Access reviews</h1>
            <p class="mt-1 text-sm" style="color:var(--muted)">Periodically certify who holds which role and membership. Revoked access is applied when the review closes.</p>
        </div>
        <button wire:click="$toggle('creating')" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New review</button>
    </div>

    @if ($creating)
        <form wire:submit="open" class="mt-6 rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="organization_id">Organization</label>
                    <select wire:model="organization_id" id="organization_id" class="select">
                        <option value="">Select an organization…</option>
                        @foreach ($organizations as $org)
                            <option value="{{ $org->id }}">{{ $org->name }}</option>
                        @endforeach
                    </select>
                    @error('organization_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="name">Review name</label>
                    <input wire:model="name" id="name" type="text" class="input" placeholder="Q3 access review" autofocus>
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Open review</button>
                <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
            </div>
            <p class="text-xs" style="color:var(--faint)">Snapshots every current role assignment and membership in the selected organization as items to certify or revoke.</p>
        </form>
    @endif

    <div class="mt-6 grid gap-5" style="grid-template-columns:minmax(0,20rem) minmax(0,1fr)">
        {{-- Campaign list --}}
        <div class="space-y-3" style="align-self:start">
            @forelse ($campaigns as $c)
                <button wire:click="select('{{ $c->id }}')" class="w-full text-left rounded-xl border p-4"
                        style="border-color:var(--border);{{ $selected === $c->id ? 'background:var(--accent-soft)' : '' }}">
                    <div class="flex items-center justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-medium truncate">{{ $c->name }}</p>
                            <p class="text-xs" style="color:var(--faint)">{{ $c->created_at?->diffForHumans() }}</p>
                        </div>
                        @if ($c->status === CampaignStatus::Open)
                            <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">Open</span>
                        @else
                            <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--success-soft);color:var(--success)">Closed</span>
                        @endif
                    </div>
                </button>
            @empty
                <p class="rounded-xl border p-4 text-sm" style="border-color:var(--border);color:var(--muted)">No reviews yet. Open a review to certify access.</p>
            @endforelse
        </div>

        {{-- Selected campaign detail --}}
        <div>
            @if ($campaign)
                <div class="flex items-center justify-between gap-3 mb-3">
                    <h2 class="font-semibold">{{ $campaign->name }}</h2>
                    @if ($campaign->status === CampaignStatus::Open)
                        <button wire:click="close('{{ $campaign->id }}')" wire:confirm="Close this review? Revoked items will be removed and pending items follow the review's policy." class="btn btn-ghost btn-sm shrink-0" style="color:var(--destructive)">Close &amp; apply</button>
                    @endif
                </div>
                <div class="rounded-xl border overflow-hidden" style="border-color:var(--border)">
                    @forelse ($items as $item)
                        <div class="flex flex-wrap items-center gap-3 p-4 {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                            <div class="min-w-0 flex-1">
                                @if ($label = ($subjectLabels[$item->subject_id] ?? null))
                                    <span class="font-medium truncate">{{ $label }}</span>
                                @else
                                    <span class="font-medium truncate mono" style="color:var(--muted)">{{ \Illuminate\Support\Str::limit($item->subject_id, 16) }}</span>
                                @endif
                                <p class="text-sm truncate" style="color:var(--muted)">
                                    <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ ucfirst($item->access_type->value) }}</span>
                                    {{ $roleNames[$item->access_ref] ?? $item->access_ref }}
                                </p>
                            </div>

                            @if ($item->decision === ReviewDecision::Certified)
                                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--success-soft);color:var(--success)">Certified</span>
                            @elseif ($item->decision === ReviewDecision::Revoked)
                                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--destructive)">Revoked</span>
                            @else
                                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">Pending</span>
                            @endif

                            @if ($campaign->status === CampaignStatus::Open)
                                <div class="flex items-center gap-2 shrink-0">
                                    <button wire:click="certify('{{ $item->id }}')" class="btn btn-ghost btn-sm">Certify</button>
                                    <button wire:click="revoke('{{ $item->id }}')" class="btn btn-ghost btn-sm" style="color:var(--destructive)">Revoke</button>
                                </div>
                            @elseif (! $item->applied && $item->decision === ReviewDecision::Revoked)
                                <span class="text-xs shrink-0" style="color:var(--destructive)" title="{{ $item->application_note }}">not applied</span>
                            @endif
                        </div>
                    @empty
                        <p class="p-4 text-sm" style="color:var(--muted)">No access in scope. This organization has no direct role or membership grants.</p>
                    @endforelse
                </div>
            @else
                <p class="rounded-xl border p-4 text-sm" style="border-color:var(--border);color:var(--muted)">Select a review on the left to certify or revoke its access.</p>
            @endif
        </div>
    </div>
</div>
