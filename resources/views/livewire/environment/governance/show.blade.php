<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Governance\Contracts\AccessReviews;
use Cbox\Id\Governance\Enums\AccessKind;
use Cbox\Id\Governance\Enums\CampaignStatus;
use Cbox\Id\Governance\Models\CertificationCampaign;
use Cbox\Id\Identity\Contracts\Subjects;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Access reviews › detail. The full, deep-linkable
 * worklist for one certification campaign: every snapshotted role assignment and
 * membership, each certified or revoked, and the close action that applies revokes.
 *
 * Every read/write re-resolves the campaign within THIS environment (the
 * CertificationCampaign model's BelongsToEnvironment scope) and 404s otherwise — an
 * id from another plane never matches (deny-by-default), so a foreign close can never
 * apply. The acting reviewer is the env-admin account member, resolved from session.
 */
new #[Layout('components.layouts.environment', ['title' => 'Access review'])] class extends Component
{
    /**
     * Second layer. The route's `env.admin` middleware is the primary gate and IS
     * re-run on Livewire actions (PersistentMiddlewareTest holds that), but this
     * console previously had NO in-component authorization at all — so when that
     * middleware was missing from the persistent list, every action here answered
     * unauthenticated. boot() rather than mount(): only boot() runs on each action.
     */
    public function boot(): void
    {
        abort_if(app(EnvironmentAdminAuth::class)->current() === null, 403);
    }

    public string $campaignId = '';

    public function mount(string $campaign): void
    {
        $model = CertificationCampaign::query()->whereKey($campaign)->first();
        abort_if($model === null, 404);

        $this->campaignId = $model->id;
    }

    private function campaign(): CertificationCampaign
    {
        $model = CertificationCampaign::query()->whereKey($this->campaignId)->first();
        abort_if($model === null, 404);

        return $model;
    }

    public function certify(string $itemId, AccessReviews $reviews): void
    {
        $reviews->certify($itemId, $this->reviewerId(), $this->campaign()->organization_id);
    }

    public function revoke(string $itemId, AccessReviews $reviews): void
    {
        $reviews->revoke($itemId, $this->reviewerId(), $this->campaign()->organization_id);
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

        $reviews->close($campaign->id, $campaign->organization_id);
        $this->dispatch('toast', message: 'Access review closed — revoked access was applied.');
    }

    /**
     * @return array<string, mixed>
     */
    public function with(AccessReviews $reviews): array
    {
        $campaign = $this->campaign();
        $items = $reviews->itemsFor($campaign->id);

        return [
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

    /** The acting reviewer: the env-admin account member for this environment. */
    private function reviewerId(): string
    {
        return app(EnvironmentAdminAuth::class)->current()?->id ?? '';
    }
}; ?>

<div class="space-y-6">
    <div>
        <a href="{{ route('environment.governance') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Access reviews</a>
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
            <p class="text-sm font-medium">Items</p>
            @if ($campaign->status === \Cbox\Id\Governance\Enums\CampaignStatus::Open)
                <button type="button" wire:click="close" wire:confirm="Close this review? Revoked items will be removed and pending items follow the review's policy." class="btn btn-ghost btn-sm shrink-0" style="color:var(--destructive)">Close &amp; apply</button>
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
                            <button type="button" wire:click="certify('{{ $item->id }}')" class="btn btn-ghost btn-sm">Certify</button>
                            <button type="button" wire:click="revoke('{{ $item->id }}')" class="btn btn-ghost btn-sm" style="color:var(--destructive)">Revoke</button>
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
        </div>
    </div>
</div>
