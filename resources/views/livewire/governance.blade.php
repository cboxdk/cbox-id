<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Governance\Contracts\AccessReviews;
use Cbox\Id\Governance\Enums\AccessKind;
use Cbox\Id\Governance\Enums\CampaignStatus;
use Cbox\Id\Governance\Enums\ReviewDecision;
use Cbox\Id\Governance\Models\CertificationCampaign;
use Cbox\Id\Identity\Contracts\Subjects;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'Access reviews'])] class extends Component
{
    #[Validate('required|string|max:190')]
    public string $name = '';

    public bool $creating = false;

    public ?string $selected = null;

    public function boot(): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);
    }

    public function open(AccessReviews $reviews): void
    {
        $this->validate();

        $campaign = $reviews->open($this->orgId(), $this->name, now()->addWeek(), createdBy: app(CurrentUser::class)->id());

        $this->reset('name', 'creating');
        $this->selected = $campaign->id;
        session()->flash('status', 'Access review "'.$campaign->name.'" opened with '.count($reviews->itemsFor($campaign->id)).' item(s).');
    }

    public function select(string $id): void
    {
        $this->selected = $id;
    }

    public function certify(string $itemId, AccessReviews $reviews): void
    {
        $reviews->certify($itemId, app(CurrentUser::class)->id(), $this->orgId());
    }

    public function revoke(string $itemId, AccessReviews $reviews): void
    {
        $reviews->revoke($itemId, app(CurrentUser::class)->id(), $this->orgId());
    }

    public function close(string $id, AccessReviews $reviews): void
    {
        $reviews->close($id, $this->orgId());
        session()->flash('status', 'Access review closed — revoked access was applied.');
    }

    public function with(): array
    {
        $reviews = app(AccessReviews::class);
        $campaign = $this->selected !== null
            ? CertificationCampaign::query()->whereKey($this->selected)->where('organization_id', $this->orgId())->first()
            : null;

        $items = $campaign !== null ? $reviews->itemsFor($campaign->id) : [];

        return [
            'me' => app(CurrentUser::class),
            'campaigns' => CertificationCampaign::query()
                ->where('organization_id', $this->orgId())
                ->orderByDesc('created_at')
                ->get(),
            'campaign' => $campaign,
            'items' => $items,
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

    private function orgId(): string
    {
        return app(CurrentUser::class)->organizationId() ?? '';
    }
}; ?>

<div>
    <div class="cbx-page-header">
        <div>
            <p class="cbx-page-eyebrow">Governance</p>
            <h1 class="cbx-page-title">Access reviews</h1>
            <p class="cbx-page-desc">Periodically certify who holds which role and membership. Revoked access is applied when the review closes.</p>
        </div>
        @if ($me->isAdmin())
            <button wire:click="$set('creating', true)" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> New review</button>
        @endif
    </div>

    @if ($creating)
        <form wire:submit="open" class="card p-4 mb-5 space-y-3">
            <div>
                <label class="label" for="name">Review name</label>
                <input wire:model="name" id="name" class="input" placeholder="Q3 access review" autofocus>
                @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Open review</button>
                <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
            </div>
            <p class="text-xs" style="color:var(--faint)">Snapshots every current role assignment and membership in this organization as items to certify or revoke.</p>
        </form>
    @endif

    <div class="grid gap-5" style="grid-template-columns:minmax(0,20rem) minmax(0,1fr)">
        {{-- Campaign list --}}
        <div class="card overflow-hidden" style="align-self:start">
            @forelse ($campaigns as $c)
                <button wire:click="select('{{ $c->id }}')" class="cbx-row w-full text-left"
                        style="{{ $selected === $c->id ? 'background:var(--accent-soft)' : '' }}">
                    <div class="min-w-0">
                        <p class="font-medium truncate">{{ $c->name }}</p>
                        <p class="text-xs" style="color:var(--faint)">{{ $c->created_at?->diffForHumans() }}</p>
                    </div>
                    @if ($c->status === CampaignStatus::Open)
                        <span class="cbx-pill cbx-pill--info"><span class="dot"></span>Open</span>
                    @else
                        <span class="cbx-pill cbx-pill--success"><span class="dot"></span>Closed</span>
                    @endif
                </button>
            @empty
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="shield" class="w-5 h-5" /></div>
                    <h3>No reviews yet</h3>
                    <p>Open a review to certify access.</p>
                </div>
            @endforelse
        </div>

        {{-- Selected campaign detail --}}
        <div>
            @if ($campaign)
                <div class="flex items-center justify-between mb-3">
                    <h2 class="font-semibold">{{ $campaign->name }}</h2>
                    @if ($campaign->status === CampaignStatus::Open)
                        <button wire:click="close('{{ $campaign->id }}')" wire:confirm="Close this review? Revoked items will be removed and pending items follow the review's policy." class="btn btn-danger btn-sm">Close &amp; apply</button>
                    @endif
                </div>
                <div class="card overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead><tr><th>Subject</th><th>Access</th><th>Decision</th><th></th></tr></thead>
                            <tbody>
                            @forelse ($items as $item)
                                <tr>
                                    <td>
                                        @if ($label = ($subjectLabels[$item->subject_id] ?? null))
                                            <span class="font-medium">{{ $label }}</span>
                                        @else
                                            <span class="mono" style="color:var(--muted)">{{ \Illuminate\Support\Str::limit($item->subject_id, 16) }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge">{{ ucfirst($item->access_type->value) }}</span>
                                        <span style="color:var(--muted)">{{ $roleNames[$item->access_ref] ?? $item->access_ref }}</span>
                                    </td>
                                    <td>
                                        @if ($item->decision === ReviewDecision::Certified)
                                            <span class="cbx-pill cbx-pill--success"><span class="dot"></span>Certified</span>
                                        @elseif ($item->decision === ReviewDecision::Revoked)
                                            <span class="cbx-pill cbx-pill--destructive"><span class="dot"></span>Revoked</span>
                                        @else
                                            <span class="cbx-pill cbx-pill--warning"><span class="dot"></span>Pending</span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        @if ($campaign->status === CampaignStatus::Open)
                                            <button wire:click="certify('{{ $item->id }}')" class="btn btn-ghost btn-sm">Certify</button>
                                            <button wire:click="revoke('{{ $item->id }}')" class="btn btn-ghost btn-sm" style="color:var(--danger)">Revoke</button>
                                        @elseif (! $item->applied && $item->decision === ReviewDecision::Revoked)
                                            <span class="text-xs" style="color:var(--warning)" title="{{ $item->application_note }}">not applied</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4"><div class="cbx-empty"><h3>No access in scope</h3><p>This organization has no direct role or membership grants.</p></div></td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @else
                <div class="cbx-empty" style="padding:3rem 1rem">
                    <div class="cbx-empty-icon"><x-icon name="shield" class="w-5 h-5" /></div>
                    <h3>Select a review</h3>
                    <p>Pick a review on the left to certify or revoke its access.</p>
                </div>
            @endif
        </div>
    </div>
</div>
