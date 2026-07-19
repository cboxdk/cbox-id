<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Governance\Contracts\AccessReviews;
use Cbox\Id\Organization\Models\Organization;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Environment control plane › Access reviews › New. A dedicated, deep-linkable page
 * that opens a certification campaign: it snapshots the selected organization's direct
 * role assignments and memberships as pending items to certify or revoke, then routes
 * straight to the new campaign's detail page.
 *
 * The organization is environment-owned, so the snapshot resolves ONLY within this
 * environment. The acting reviewer is the env-admin account member (a fourth-plane
 * identity), resolved from the env-admin session — not a subject inside the tenant.
 */
new #[Layout('components.layouts.environment', ['title' => 'New access review'])] class extends Component
{
    #[Validate('required|string|max:190')]
    public string $name = '';

    #[Validate('required|string')]
    public string $organization_id = '';

    /**
     * Open a campaign: snapshot the selected organization's direct role assignments
     * and memberships as pending items, then route to its detail page.
     */
    public function open(AccessReviews $reviews): mixed
    {
        $this->validate();

        if (Organization::query()->whereKey($this->organization_id)->doesntExist()) {
            $this->addError('organization_id', 'That organization is not in this environment.');

            return null;
        }

        $campaign = $reviews->open(
            $this->organization_id,
            $this->name,
            now()->addWeek(),
            createdBy: $this->reviewerId(),
        );

        session()->flash('status', 'Access review "'.$campaign->name.'" opened with '.count($reviews->itemsFor($campaign->id)).' item(s).');

        return $this->redirectRoute('environment.governance.show', ['campaign' => $campaign->id], navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'organizations' => Organization::query()->orderBy('name')->get(),
        ];
    }

    /** The acting reviewer: the env-admin account member for this environment. */
    private function reviewerId(): string
    {
        return app(EnvironmentAdminAuth::class)->current()?->id ?? '';
    }
}; ?>

<div>
    <a href="{{ route('environment.governance') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Access reviews</a>
    <x-page-header class="mt-2" title="New access review" subtitle="Snapshots every current role assignment and membership in the selected organization as items to certify or revoke." />

    <form wire:submit="open" class="mt-6 max-w-xl rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
        <div>
            <label class="label" for="organization_id">Organization</label>
            <select wire:model="organization_id" id="organization_id" class="select">
                <option value="">Select an organization…</option>
                @foreach ($organizations as $org)
                    <option value="{{ $org->id }}">{{ $org->name }}</option>
                @endforeach
            </select>
            @error('organization_id') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="label" for="name">Review name</label>
            <input wire:model="name" id="name" type="text" class="input" placeholder="Q3 access review" autofocus>
            @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="open">Open review</button>
            <a href="{{ route('environment.governance') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
