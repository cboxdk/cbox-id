<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Cbox\Id\Governance\Models\SodPolicy;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'Segregation of duties'])] class extends Component
{
    #[Validate('required|string|max:190')]
    public string $name = '';

    /** @var list<string> */
    public array $roleIds = [];

    public bool $creating = false;

    public function boot(): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);
    }

    public function define(SegregationOfDuties $sod): void
    {
        $this->validate();

        if (count($this->roleIds) < 2) {
            $this->addError('roleIds', 'Select at least two roles for a mutually-exclusive set.');

            return;
        }

        $policy = $sod->definePolicy($this->orgId(), $this->name, array_values($this->roleIds));

        $this->reset('name', 'roleIds', 'creating');
        $this->dispatch('toast', message: 'Policy "'.$policy->name.'" defined over '.count($policy->role_ids).' roles.');
    }

    public function toggle(string $id, SegregationOfDuties $sod): void
    {
        $policy = SodPolicy::query()
            ->whereKey($id)
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $this->orgId()))
            ->firstOrFail();

        $sod->setActive($policy->id, ! $policy->active);
    }

    public function with(): array
    {
        $orgId = $this->orgId();

        $roles = Role::query()->where('organization_id', $orgId)->orderBy('name')->get();

        return [
            'me' => app(CurrentUser::class),
            'roles' => $roles,
            'roleNames' => $roles->pluck('name', 'id'),
            'policies' => SodPolicy::query()
                ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $orgId))
                ->orderByDesc('id')
                ->get(),
            'violations' => app(SegregationOfDuties::class)->scan($orgId),
        ];
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
            <h1 class="cbx-page-title">Segregation of duties</h1>
            <p class="cbx-page-desc">Declare sets of roles that must never be held together, then detect subjects who already hold a toxic combination.</p>
        </div>
        @if ($me->isAdmin())
            <button wire:click="$set('creating', true)" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> New policy</button>
        @endif
    </div>

    @if ($creating)
        <form wire:submit="define" class="card p-4 mb-5 space-y-3">
            <div>
                <label class="label" for="name">Policy name</label>
                <input wire:model="name" id="name" class="input" placeholder="Purchase order vs. approve payment" autofocus>
                @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <span class="label">Mutually-exclusive roles</span>
                @forelse ($roles as $role)
                    <label class="flex items-center gap-2 py-1">
                        <input type="checkbox" wire:model="roleIds" value="{{ $role->id }}">
                        <span>{{ $role->name }}</span>
                    </label>
                @empty
                    <p class="text-xs" style="color:var(--faint)">This organization has no roles to choose from yet.</p>
                @endforelse
                @error('roleIds') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Define policy</button>
                <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
            </div>
            <p class="text-xs" style="color:var(--faint)">Holding two or more roles from the set at once is a violation.</p>
        </form>
    @endif

    {{-- Existing policies --}}
    <div class="card overflow-hidden mb-5">
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Policy</th><th>Roles in set</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @forelse ($policies as $policy)
                    <tr>
                        <td class="font-medium">{{ $policy->name }}</td>
                        <td>
                            @foreach ($policy->role_ids as $roleId)
                                <span class="badge">{{ $roleNames[$roleId] ?? $roleId }}</span>
                            @endforeach
                        </td>
                        <td>
                            @if ($policy->active)
                                <span class="cbx-pill cbx-pill--success"><span class="dot"></span>Active</span>
                            @else
                                <span class="cbx-pill cbx-pill--warning"><span class="dot"></span>Inactive</span>
                            @endif
                        </td>
                        <td class="text-right">
                            <button wire:click="toggle('{{ $policy->id }}')" class="btn btn-ghost btn-sm">{{ $policy->active ? 'Deactivate' : 'Activate' }}</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4"><div class="cbx-empty">
                        <div class="cbx-empty-icon"><x-icon name="shield" class="w-5 h-5" /></div>
                        <h3>No policies yet</h3>
                        <p>Define a policy to forbid a toxic combination of roles.</p>
                    </div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Violations --}}
    <div>
        <h2 class="font-semibold mb-3">Violations</h2>
        @forelse ($violations as $violation)
            <div class="card p-4 mb-3" style="border-color:var(--warning-strong)">
                <div class="flex items-center gap-2 mb-1">
                    <span class="cbx-pill cbx-pill--warning"><span class="dot"></span>{{ $violation->policyName }}</span>
                    <span class="mono">{{ $violation->subjectId }}</span>
                </div>
                <p class="text-sm" style="color:var(--muted)">
                    Holds conflicting roles:
                    @foreach ($violation->conflictingRoleIds as $roleId)
                        <span class="badge">{{ $roleNames[$roleId] ?? $roleId }}</span>
                    @endforeach
                </p>
            </div>
        @empty
            <p class="text-sm" style="color:var(--faint)">No conflicts detected.</p>
        @endforelse
    </div>
</div>
