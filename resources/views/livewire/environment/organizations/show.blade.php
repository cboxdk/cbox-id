<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Organizations › detail. The full, deep-linkable
 * lifecycle for one tenant: rename, URL handle, metadata, members (add/role/remove),
 * suspend/reactivate and delete.
 *
 * Reads/writes resolve within THIS environment (BelongsToEnvironment) and 404 on a
 * foreign id. suspend/reactivate go through the audited {@see Organizations} service
 * (actor = the env-admin account member); rename/handle/metadata persist on the
 * env-scoped model (no rename service exists); delete is a soft status change.
 */
new #[Layout('components.layouts.environment')] class extends Component
{
    public string $orgId = '';

    public string $editName = '';

    public string $editSlug = '';

    /** @var list<array{key: string, value: string}> */
    public array $metadata = [];

    public string $memberEmail = '';

    public string $memberRole = 'member';

    public function mount(string $organization): void
    {
        $org = Organization::query()->whereKey($organization)->first();
        abort_if($org === null, 404);

        $this->orgId = $org->id;
        $this->hydrateForm($org);
    }

    private function org(): Organization
    {
        $org = Organization::query()->whereKey($this->orgId)->first();
        abort_if($org === null, 404);

        return $org;
    }

    private function hydrateForm(Organization $org): void
    {
        $this->editName = $org->name;
        $this->editSlug = $org->slug;

        $this->metadata = [];
        $meta = $org->settings['metadata'] ?? [];
        if (is_array($meta)) {
            foreach ($meta as $key => $value) {
                $this->metadata[] = ['key' => (string) $key, 'value' => is_scalar($value) ? (string) $value : ''];
            }
        }
    }

    public function addMetaRow(): void
    {
        $this->metadata[] = ['key' => '', 'value' => ''];
    }

    public function removeMetaRow(int $i): void
    {
        unset($this->metadata[$i]);
        $this->metadata = array_values($this->metadata);
    }

    public function saveDetails(Organizations $organizations): void
    {
        $org = $this->org();

        $data = $this->validate([
            'editName' => ['required', 'string', 'max:190'],
            'editSlug' => ['required', 'string', 'max:190', 'alpha_dash'],
            'metadata.*.key' => ['nullable', 'string', 'max:120'],
            'metadata.*.value' => ['nullable', 'string', 'max:500'],
        ]);

        $slug = Str::slug($data['editSlug']);
        $existing = $organizations->bySlug($slug);
        if ($existing !== null && $existing->id !== $org->id) {
            $this->addError('editSlug', 'That URL handle is already used by another organization.');

            return;
        }

        // Preserve any other settings keys; only the metadata subtree is edited here.
        $settings = $org->settings;
        $metaOut = [];
        foreach ($this->metadata as $row) {
            $key = trim($row['key']);
            if ($key !== '') {
                $metaOut[$key] = trim($row['value']);
            }
        }
        if ($metaOut === []) {
            unset($settings['metadata']);
        } else {
            $settings['metadata'] = $metaOut;
        }

        $org->name = trim($data['editName']);
        $org->slug = $slug;
        $org->settings = $settings;
        $org->save();

        session()->flash('status', 'Organization updated.');
    }

    public function suspend(Organizations $organizations): void
    {
        $organizations->suspend($this->org()->id, $this->actorId());
        session()->flash('status', 'Organization suspended.');
    }

    public function reactivate(Organizations $organizations): void
    {
        $organizations->reactivate($this->org()->id, $this->actorId());
        session()->flash('status', 'Organization reactivated.');
    }

    public function deleteOrg(): mixed
    {
        $org = $this->org();
        $org->status = OrganizationStatus::Deleted;
        $org->save();

        session()->flash('status', 'Organization deleted.');

        return $this->redirectRoute('environment.organizations', navigate: true);
    }

    public function addMember(Memberships $memberships): void
    {
        $org = $this->org();

        $this->validate([
            'memberEmail' => ['required', 'email', 'max:190'],
            'memberRole' => ['required', 'in:member,admin,owner'],
        ]);

        $user = User::query()->where('email', $this->memberEmail)->first();
        if ($user === null) {
            $this->addError('memberEmail', 'No user with that email in this environment. Create the user first.');

            return;
        }

        if ($memberships->of($org->id, $user->id) !== null) {
            $this->addError('memberEmail', 'That user is already a member.');

            return;
        }

        $memberships->add($org->id, $user->id, $this->memberRole);

        $this->memberEmail = '';
        $this->memberRole = 'member';
        session()->flash('status', 'Member added.');
    }

    public function changeMemberRole(string $userId, string $role, Memberships $memberships): void
    {
        if (in_array($role, ['member', 'admin', 'owner'], true)) {
            $memberships->changeRole($this->org()->id, $userId, $role);
            session()->flash('status', 'Role updated.');
        }
    }

    public function removeMember(string $userId, Memberships $memberships): void
    {
        $memberships->remove($this->org()->id, $userId);
        session()->flash('status', 'Member removed.');
    }

    private function actorId(): string
    {
        return app(EnvironmentAdminAuth::class)->current()?->id ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Memberships $memberships): array
    {
        $org = $this->org();

        /** @var \Illuminate\Support\Collection<string, \Cbox\Id\Identity\Models\User> $userMap */
        $userMap = User::query()->get()->keyBy('id');

        $members = [];
        foreach ($memberships->forOrganization($org->id) as $m) {
            $u = $userMap->get($m->user_id);
            $members[] = [
                'userId' => $m->user_id,
                'name' => $u?->name ?? $u?->email ?? $m->user_id,
                'email' => $u?->email,
                'role' => $m->role,
            ];
        }

        return ['org' => $org, 'members' => $members];
    }
}; ?>

<div class="space-y-6">
    <div>
        <a href="{{ route('environment.organizations') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Organizations</a>
        <div class="mt-2 flex items-center gap-3 flex-wrap">
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">{{ $org->name }}</h1>
            <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $org->status->value }}</span>
        </div>
        <p class="mt-1 text-sm mono" style="color:var(--faint)">{{ $org->id }}</p>
    </div>

    {{-- Details --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Details</p>
        <form wire:submit="saveDetails" class="mt-4 space-y-4">
            <div class="grid sm:grid-cols-2 gap-3">
                <div>
                    <label class="label" for="editName">Name</label>
                    <input wire:model="editName" id="editName" type="text" class="input">
                    @error('editName') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="editSlug">URL handle</label>
                    <input wire:model="editSlug" id="editSlug" type="text" class="input mono">
                    @error('editSlug') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="label">Metadata</label>
                <div class="space-y-2">
                    @foreach ($metadata as $i => $row)
                        <div class="flex items-center gap-2" wire:key="meta-{{ $i }}">
                            <input wire:model="metadata.{{ $i }}.key" type="text" class="input mono" placeholder="tier">
                            <input wire:model="metadata.{{ $i }}.value" type="text" class="input" placeholder="enterprise">
                            <button type="button" class="btn btn-ghost btn-sm shrink-0" style="color:var(--destructive)" wire:click="removeMetaRow({{ $i }})" aria-label="Remove"><x-icon name="close" class="w-4 h-4" /></button>
                        </div>
                    @endforeach
                </div>
                <button type="button" class="btn btn-ghost btn-sm mt-2" wire:click="addMetaRow">+ Add field</button>
            </div>
            <button type="submit" class="btn btn-primary">Save changes</button>
        </form>
    </div>

    {{-- Members --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Members</p>
        <div class="mt-4 space-y-2">
            @forelse ($members as $m)
                <div class="flex items-center gap-2 rounded-lg border px-3 py-2" style="border-color:var(--border)" wire:key="member-{{ $m['userId'] }}">
                    <a href="{{ route('environment.users.show', $m['userId']) }}" class="min-w-0 flex-1" style="color:inherit">
                        <span class="block text-sm font-medium truncate">{{ $m['name'] }}</span>
                        @if ($m['email'])<span class="block text-xs truncate mono" style="color:var(--faint)">{{ $m['email'] }}</span>@endif
                    </a>
                    <select class="select" style="width:auto" wire:change="changeMemberRole('{{ $m['userId'] }}', $event.target.value)">
                        @foreach (['member' => 'Member', 'admin' => 'Admin', 'owner' => 'Owner'] as $val => $lbl)
                            <option value="{{ $val }}" @selected($m['role'] === $val)>{{ $lbl }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="btn btn-ghost btn-sm shrink-0" style="color:var(--destructive)" wire:click="removeMember('{{ $m['userId'] }}')" wire:confirm="Remove this member?">Remove</button>
                </div>
            @empty
                <p class="text-sm" style="color:var(--muted)">No members yet.</p>
            @endforelse
        </div>
        <form wire:submit="addMember" class="mt-4 grid sm:grid-cols-[1fr_auto_auto] gap-2 items-start">
            <div>
                <input wire:model="memberEmail" type="email" class="input" placeholder="existing-user@example.com">
                @error('memberEmail') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <select wire:model="memberRole" class="select">
                <option value="member">Member</option>
                <option value="admin">Admin</option>
                <option value="owner">Owner</option>
            </select>
            <button type="submit" class="btn btn-primary shrink-0">Add member</button>
        </form>
    </div>

    {{-- Lifecycle --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Lifecycle</p>
        <div class="mt-4 flex flex-wrap gap-2">
            @if ($org->status === OrganizationStatus::Suspended)
                <button type="button" class="btn btn-ghost btn-sm" wire:click="reactivate">Reactivate</button>
            @else
                <button type="button" class="btn btn-ghost btn-sm" wire:click="suspend" wire:confirm="Suspend this organization? Its members lose access until reactivated.">Suspend</button>
            @endif
            <button type="button" class="btn btn-ghost btn-sm" style="color:var(--destructive)" wire:click="deleteOrg" wire:confirm="Delete this organization? It is hidden and its members lose access.">Delete organization</button>
        </div>
    </div>
</div>
