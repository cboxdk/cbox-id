<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use Cbox\Id\Organization\Contracts\OrganizationHierarchy;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Exceptions\CannotReparent;
use Cbox\Id\Organization\Enums\OrganizationType;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Tenant management for the target environment — the operator's view of every
 * organization in the plane, as the closure-tree hierarchy (reseller → customer →
 * sub-unit, arbitrary depth). Queries are naturally scoped to the pinned
 * environment, so this is the whole plane and never another's.
 */
new #[Layout('components.layouts.app', ['title' => 'Organizations'])] class extends Component
{
    /**
     * SEARCH, AND DELIBERATELY NO PAGING.
     *
     * This list is a management TREE, flattened depth-first so the table reads as the
     * hierarchy. Paging the flattened rows would cut the tree at an arbitrary depth — a
     * child on page two with its parent on page one reads as a root, which is worse than
     * a long page. Narrowing by name or slug is the operation an operator actually wants
     * here, and it keeps whatever matches intact.
     *
     * If the estate ever outgrows one page, the answer is paging the ROOTS and rendering
     * each subtree whole, not paging these rows.
     */
    #[Url(as: 'q')]
    public string $search = '';

    public bool $creating = false;

    public string $name = '';

    public string $type = 'customer';

    public ?string $parentId = null;

    /** Re-check operator AUTHORITY on every request, including Livewire actions. */
    public function boot(ConsoleScope $scope): void
    {
        abort_unless($scope->isPlatformOperator(), 404);
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
        $this->dispatch('toast', message: 'Organization created.');
    }

    public function toggleStatus(string $id, Organizations $orgs, ConsoleScope $scope): void
    {
        $org = Organization::query()->find($id);
        if ($org === null) {
            return;
        }

        // Route the status change through the Organizations contract so it is
        // attributed to the acting operator and recorded on the tenant's audit
        // trail — a direct ->update() would bypass both.
        $actorId = $scope->operator()?->id;
        if ($actorId === null) {
            abort(403);
        }

        if ($org->status === OrganizationStatus::Active) {
            $orgs->suspend($id, $actorId);
            $this->dispatch('toast', message: 'Organization suspended.');
        } else {
            $orgs->reactivate($id, $actorId);
            $this->dispatch('toast', message: 'Organization reactivated.');
        }
    }

    public function reparent(string $id, string $parentId, OrganizationHierarchy $hierarchy): void
    {
        try {
            // move() rewrites the closure subtree and guards against cycles.
            $hierarchy->move($id, $parentId !== '' ? $parentId : null);
        } catch (CannotReparent) {
            $this->dispatch('toast', message: 'That would create a cycle in the hierarchy — ignored.', severity: 'error');

            return;
        }

        $this->dispatch('toast', message: 'Hierarchy updated.');
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

    /** @return array<string, mixed> */
    public function with(): array
    {
        $term = trim($this->search);

        $orgs = Organization::query()->orderBy('name')
            ->when($term !== '', function ($q) use ($term): void {
                // Grouped, so the environment scope's own predicate is not stranded
                // behind the OR.
                $q->where(function ($inner) use ($term): void {
                    $inner->where('name', 'like', "%{$term}%")->orWhere('slug', 'like', "%{$term}%");
                });
            })
            ->get(['id', 'name', 'slug', 'type', 'status', 'parent_id']);

        /** @var Collection<string, int> $memberCounts */
        $memberCounts = Membership::query()->selectRaw('organization_id, count(*) as c')
            ->groupBy('organization_id')->pluck('c', 'organization_id');

        // Depth-first flatten so the table reads as the management tree.
        //
        // A FILTERED SET HAS ORPHANS, and they must not vanish. The walk starts at the
        // roots — the '' bucket — so an organization whose parent did not match the search
        // would be grouped under a parent key nothing ever visits, and a match would
        // silently not be listed. A search that hides matches is worse than no search.
        //
        // So a row whose parent is absent from the result set is treated as a root for
        // display. It renders at depth 0 rather than under a parent that is not on screen,
        // which is the honest thing to show: this is a filtered view, not the tree.
        $present = $orgs->keyBy(fn (Organization $o): string => $o->id);

        /** @var Collection<string, Collection<int, Organization>> $byParent */
        $byParent = $orgs->groupBy(function (Organization $o) use ($present): string {
            $parent = $o->parent_id;

            return $parent !== null && $present->has($parent) ? $parent : '';
        });
        $rows = [];
        $walk = function (string $parentKey, int $depth) use (&$walk, $byParent, $memberCounts, &$rows): void {
            /** @var Collection<int, Organization> $children */
            $children = $byParent->get($parentKey, new Collection);

            foreach ($children as $o) {
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
            'all' => $orgs->map(fn (Organization $o) => ['id' => $o->id, 'name' => $o->name])->values()->all(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Organizations" subtitle="Every tenant in the target environment — the management tree of resellers, customers and sub-units.">
        <x-slot:actions>
            <button wire:click="$toggle('creating')" class="btn btn-primary">
                <x-icon name="plus" class="w-4 h-4" /> New organization
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem"
               placeholder="Search by name or slug" aria-label="Search organizations">
    </div>

    <p role="status" aria-live="polite" class="sr-only">{{ count($rows) }} {{ \Illuminate\Support\Str::plural('organization', count($rows)) }} found.</p>

    @if ($creating)
        <form wire:submit="create" class="card p-4 mb-5 mt-8 flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[12rem]">
                <label class="label" for="org-name">Name</label>
                <input wire:model="name" id="org-name" type="text" class="input" placeholder="Acme Inc" autofocus
                       @error('name') aria-invalid="true" aria-describedby="org-name-error" @enderror>
                @error('name') <p id="org-name-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="org-type">Type</label>
                <select wire:model="type" id="org-type" class="select">
                    <option value="customer">Customer</option>
                    <option value="reseller">Reseller</option>
                </select>
            </div>
            <div class="min-w-[12rem]">
                <label class="label" for="org-parent">Parent <span style="color:var(--faint)">(optional)</span></label>
                <select wire:model="parentId" id="org-parent" class="select">
                    <option value="">— Top level —</option>
                    @foreach ($all as $o)
                        <option value="{{ $o['id'] }}">{{ $o['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">
                <span class="spinner" wire:loading wire:target="create" aria-hidden="true"></span> Create
            </button>
            <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
        </form>
    @endif

    @if ($rows === [])
        <div class="cbx-empty mt-8">
            <div class="cbx-empty-icon"><x-icon name="layers" class="w-5 h-5" /></div>
            <h3>No organizations in this environment</h3>
            <p>A tenant is where users, sign-in methods and roles live. Create one above, or bootstrap the plane with its first organization and admin from the Environments screen.</p>
        </div>
    @else
        {{-- A real table for the same reason Accounts is one: the header row and the body
             rows were two independent grids over the same `fr` tracks, so they lined up
             only by luck. --}}
        <div class="cbx-panel overflow-hidden mt-8">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Organization</th>
                            <th scope="col">Type</th>
                            <th scope="col" class="text-right">Members</th>
                            <th scope="col">Parent</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr wire:key="org-{{ $row['id'] }}">
                                <td>
                                    <div class="flex items-center" style="padding-left:{{ $row['depth'] * 1.25 }}rem">
                                        @if ($row['depth'] > 0)
                                            <span aria-hidden="true" style="color:var(--faint);margin-right:.4rem">└</span>
                                        @endif
                                        <div class="min-w-0">
                                            <p class="font-semibold">
                                                {{ $row['name'] }}
                                                @if ($row['status'] === 'suspended')
                                                    <span class="cbx-pill cbx-pill--destructive align-middle ml-1"><span class="dot"></span>Suspended</span>
                                                @endif
                                            </p>
                                            <p class="text-xs font-mono" style="color:var(--faint)">{{ $row['slug'] }}</p>
                                        </div>
                                    </div>
                                </td>

                                <td class="capitalize whitespace-nowrap" style="color:var(--muted)">{{ $row['type'] }}</td>
                                <td class="text-right tabular-nums">{{ $row['members'] }}</td>

                                <td>
                                    <select class="select"
                                            wire:change="reparent('{{ $row['id'] }}', $event.target.value)"
                                            wire:loading.attr="disabled" wire:target="reparent"
                                            aria-label="Parent organization for {{ $row['name'] }}">
                                        <option value="" @selected($row['parent_id'] === null)>— Top level —</option>
                                        @foreach ($all as $o)
                                            @if ($o['id'] !== $row['id'])
                                                <option value="{{ $o['id'] }}" @selected($row['parent_id'] === $o['id'])>{{ $o['name'] }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </td>

                                <td class="text-right whitespace-nowrap">
                                    <a href="{{ route('platform.organization', $row['id']) }}" wire:navigate class="btn btn-ghost btn-sm">
                                        View
                                    </a>
                                    {{-- Suspending a live tenant sat 8px right of "View" with no dialog,
                                         no undo and no toast. Same copy pattern as Accounts: name the
                                         tenant, say who stops being able to sign in. Reversible here, so
                                         wire:confirm rather than the type-to-confirm dialog. --}}
                                    <button wire:click="toggleStatus('{{ $row['id'] }}')" class="btn btn-ghost btn-sm"
                                            wire:loading.attr="disabled" wire:target="toggleStatus('{{ $row['id'] }}')"
                                            wire:confirm="{{ $row['status'] === 'active'
                                                ? 'Suspend '.$row['name'].'? Its '.$row['members'].' member(s) can no longer sign in to this tenant, and any app relying on it stops authenticating them. Sub-organizations are not suspended with it. You can reactivate it here.'
                                                : 'Reactivate '.$row['name'].'? Its members can sign in again immediately.' }}">
                                        <span class="spinner" wire:loading wire:target="toggleStatus('{{ $row['id'] }}')" aria-hidden="true"></span>
                                        {{ $row['status'] === 'active' ? 'Suspend' : 'Reactivate' }}
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
