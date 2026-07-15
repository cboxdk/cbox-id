<?php

declare(strict_types=1);

use App\Platform\OperatorAuth;
use Cbox\Id\Organization\Contracts\OrganizationHierarchy;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Exceptions\CannotReparent;
use Cbox\Id\Organization\Enums\OrganizationType;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Tenant management for the target environment — the operator's view of every
 * organization in the plane, as the closure-tree hierarchy (reseller → customer →
 * sub-unit, arbitrary depth). Queries are naturally scoped to the pinned
 * environment, so this is the whole plane and never another's.
 */
new #[Layout('components.layouts.operator', ['title' => 'Organizations'])] class extends Component
{
    public bool $creating = false;

    public string $name = '';

    public string $type = 'customer';

    public ?string $parentId = null;

    /** Re-check operator auth on every request, including Livewire actions. */
    public function boot(OperatorAuth $auth): void
    {
        abort_unless($auth->check(), 403);
    }

    public function create(Organizations $orgs): void
    {
        $this->validate([
            'name' => 'required|string|max:190',
            'type' => 'required|in:customer,reseller',
            'parentId' => 'nullable|string',
        ]);

        $orgs->create(new NewOrganization(
            name: $this->name,
            slug: $this->uniqueSlug($this->name),
            type: OrganizationType::from($this->type),
            parentId: $this->parentId !== '' ? $this->parentId : null,
        ));

        $this->reset('name', 'type', 'parentId', 'creating');
        session()->flash('status', 'Organization created.');
    }

    public function toggleStatus(string $id, Organizations $orgs, OperatorAuth $auth): void
    {
        $org = Organization::query()->find($id);
        if ($org === null) {
            return;
        }

        // Route the status change through the Organizations contract so it is
        // attributed to the acting operator and recorded on the tenant's audit
        // trail — a direct ->update() would bypass both.
        $actorId = $auth->id();
        if ($actorId === null) {
            abort(403);
        }

        if ($org->status === OrganizationStatus::Active) {
            $orgs->suspend($id, $actorId);
            session()->flash('status', 'Organization suspended.');
        } else {
            $orgs->reactivate($id, $actorId);
            session()->flash('status', 'Organization reactivated.');
        }
    }

    public function reparent(string $id, string $parentId, OrganizationHierarchy $hierarchy): void
    {
        try {
            // move() rewrites the closure subtree and guards against cycles.
            $hierarchy->move($id, $parentId !== '' ? $parentId : null);
        } catch (CannotReparent) {
            session()->flash('status', 'That would create a cycle in the hierarchy — ignored.');

            return;
        }

        session()->flash('status', 'Hierarchy updated.');
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';
        $slug = $base;
        $n = 2;

        while (Organization::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    public function with(): array
    {
        $orgs = Organization::query()->orderBy('name')
            ->get(['id', 'name', 'slug', 'type', 'status', 'parent_id']);

        $memberCounts = Membership::query()->selectRaw('organization_id, count(*) as c')
            ->groupBy('organization_id')->pluck('c', 'organization_id');

        // Depth-first flatten so the table reads as the management tree.
        $byParent = $orgs->groupBy(fn ($o) => $o->parent_id ?? '');
        $rows = [];
        $walk = function (string $parentKey, int $depth) use (&$walk, $byParent, $memberCounts, &$rows): void {
            foreach ($byParent->get($parentKey, collect()) as $o) {
                $rows[] = [
                    'id' => $o->id,
                    'name' => $o->name,
                    'slug' => $o->slug,
                    'type' => $o->type->value,
                    'status' => $o->status->value,
                    'parent_id' => $o->parent_id,
                    'depth' => $depth,
                    'members' => (int) ($memberCounts[$o->id] ?? 0),
                ];
                $walk($o->id, $depth + 1);
            }
        };
        $walk('', 0);

        return [
            'rows' => $rows,
            // Flat list for the parent selectors.
            'all' => $orgs->map(fn ($o) => ['id' => $o->id, 'name' => $o->name])->values()->all(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Organizations"
                   subtitle="Every tenant in the target environment — the management tree of resellers, customers and sub-units.">
        <x-slot:actions>
            <button wire:click="$toggle('creating')" class="btn btn-primary">
                <x-icon name="plus" class="w-4 h-4" /> New organization
            </button>
        </x-slot:actions>
    </x-page-header>

    @if ($creating)
        <form wire:submit="create" class="card p-4 mb-5 flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[12rem]">
                <label class="label" for="org-name">Name</label>
                <input wire:model="name" id="org-name" type="text" class="input" placeholder="Acme Inc" autofocus>
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="org-type">Type</label>
                <select wire:model="type" id="org-type" class="input">
                    <option value="customer">Customer</option>
                    <option value="reseller">Reseller</option>
                </select>
            </div>
            <div class="min-w-[12rem]">
                <label class="label" for="org-parent">Parent <span style="color:var(--faint)">(optional)</span></label>
                <select wire:model="parentId" id="org-parent" class="input">
                    <option value="">— Top level —</option>
                    @foreach ($all as $o)
                        <option value="{{ $o['id'] }}">{{ $o['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Create</button>
            <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
        </form>
    @endif

    <div class="card overflow-hidden">
        <div class="hidden sm:grid px-5 py-3 border-b text-xs font-medium uppercase tracking-wide"
             style="border-color:var(--border);color:var(--faint);grid-template-columns:2.5fr 1fr 1fr 1.4fr auto">
            <span>Organization</span><span>Type</span><span>Members</span><span>Parent</span><span></span>
        </div>

        @forelse ($rows as $row)
            <div class="px-5 py-3 border-b flex flex-col gap-2 sm:grid sm:items-center sm:gap-4"
                 style="border-color:var(--border);grid-template-columns:2.5fr 1fr 1fr 1.4fr auto">
                <div class="min-w-0 flex items-center" style="padding-left:{{ $row['depth'] * 1.25 }}rem">
                    @if ($row['depth'] > 0)
                        <span aria-hidden="true" style="color:var(--faint);margin-right:.4rem">└</span>
                    @endif
                    <div class="min-w-0">
                        <p class="text-sm font-semibold truncate">
                            {{ $row['name'] }}
                            @if ($row['status'] === 'suspended')
                                <span class="badge badge-danger align-middle ml-1">Suspended</span>
                            @endif
                        </p>
                        <p class="text-xs font-mono truncate" style="color:var(--faint)">{{ $row['slug'] }}</p>
                    </div>
                </div>

                <div class="text-sm capitalize" style="color:var(--muted)">{{ $row['type'] }}</div>
                <div class="text-sm"><span class="sm:hidden" style="color:var(--faint)">Members: </span>{{ $row['members'] }}</div>

                <div>
                    <select class="input" style="padding:.3rem .5rem;font-size:.8rem"
                            wire:change="reparent('{{ $row['id'] }}', $event.target.value)"
                            aria-label="Parent organization for {{ $row['name'] }}">
                        <option value="" @selected($row['parent_id'] === null)>— Top level —</option>
                        @foreach ($all as $o)
                            @if ($o['id'] !== $row['id'])
                                <option value="{{ $o['id'] }}" @selected($row['parent_id'] === $o['id'])>{{ $o['name'] }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>

                <div class="flex items-center gap-1 sm:justify-self-end">
                    <a href="{{ route('operator.organization', $row['id']) }}" wire:navigate class="btn btn-ghost btn-sm">
                        View
                    </a>
                    <button wire:click="toggleStatus('{{ $row['id'] }}')" class="btn btn-ghost btn-sm">
                        {{ $row['status'] === 'active' ? 'Suspend' : 'Reactivate' }}
                    </button>
                </div>
            </div>
        @empty
            <div class="px-5 py-10 text-center text-sm" style="color:var(--faint)">
                No organizations in this environment yet. Create one, or bootstrap the plane from the Environments screen.
            </div>
        @endforelse
    </div>
</div>
