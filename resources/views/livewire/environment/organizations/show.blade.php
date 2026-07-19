<?php

declare(strict_types=1);

use App\Mail\InvitationMail;
use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Exceptions\DomainAlreadyClaimed;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Exceptions\LastOwner;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Support\Facades\Mail;
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
new #[Layout('components.layouts.environment', ['title' => 'Organization'])] class extends Component
{
    public string $orgId = '';

    public string $editName = '';

    public string $editSlug = '';

    /** @var list<array{key: string, value: string}> */
    public array $metadata = [];

    public string $memberEmail = '';

    public string $memberRole = 'member';

    public string $inviteEmail = '';

    public string $inviteRole = 'member';

    public string $newDomain = '';

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
        $org = $this->org();
        if (! in_array($role, ['member', 'admin', 'owner'], true) || $memberships->of($org->id, $userId) === null) {
            return;
        }

        try {
            $memberships->changeRole($org->id, $userId, $role);
            session()->flash('status', 'Role updated.');
        } catch (LastOwner) {
            session()->flash('status', 'An organization must keep at least one owner.');
        }
    }

    public function removeMember(string $userId, Memberships $memberships): void
    {
        $org = $this->org();
        if ($memberships->of($org->id, $userId) === null) {
            return;
        }

        try {
            $memberships->remove($org->id, $userId);
            session()->flash('status', 'Member removed.');
        } catch (LastOwner) {
            session()->flash('status', 'An organization must keep at least one owner.');
        }
    }

    public function invite(Invitations $invitations): void
    {
        $org = $this->org();

        $this->validate([
            'inviteEmail' => ['required', 'email', 'max:190'],
            'inviteRole' => ['required', 'in:member,admin,owner'],
        ]);

        // The invitee accepts via the emailed token — no one is added without consent.
        $pending = $invitations->invite($org->id, $this->inviteEmail, $this->inviteRole);
        Mail::to($this->inviteEmail)->send(new InvitationMail(
            organization: $org->name,
            inviter: app(EnvironmentAdminAuth::class)->current()?->name ?? 'An administrator',
            url: route('invitation.accept', $pending->token),
        ));

        $this->inviteEmail = '';
        $this->inviteRole = 'member';
        session()->flash('status', 'Invitation sent to '.$pending->invitation->email.'.');
    }

    public function revokeInvitation(string $id, Invitations $invitations): void
    {
        $invitations->revoke($this->org()->id, $id);
        session()->flash('status', 'Invitation revoked.');
    }

    public function addDomain(DomainVerification $domains): void
    {
        $org = $this->org();

        $this->validate(['newDomain' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i']]);

        try {
            $domains->add($org->id, mb_strtolower(trim($this->newDomain)));
        } catch (DomainAlreadyClaimed) {
            $this->addError('newDomain', 'That domain is already claimed.');

            return;
        }

        $this->newDomain = '';
        session()->flash('status', 'Domain added — add the DNS TXT record shown below, then verify.');
    }

    public function verifyDomain(string $id, DomainVerification $domains): void
    {
        if (! $this->ownsDomain($id)) {
            return;
        }

        session()->flash('status', $domains->verify($id)
            ? 'Domain verified.'
            : 'Verification failed — the DNS TXT record was not found yet.');
    }

    public function toggleCapture(string $id, DomainVerification $domains): void
    {
        $domain = VerifiedDomain::query()->whereKey($id)->where('organization_id', $this->org()->id)->first();
        if ($domain !== null) {
            $domains->setCapture($id, ! $domain->capture);
            session()->flash('status', 'Domain capture updated.');
        }
    }

    public function removeDomain(string $id, DomainVerification $domains): void
    {
        if ($this->ownsDomain($id)) {
            $domains->remove($id);
            session()->flash('status', 'Domain removed.');
        }
    }

    private function ownsDomain(string $id): bool
    {
        return VerifiedDomain::query()->whereKey($id)->where('organization_id', $this->org()->id)->exists();
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

        return [
            'org' => $org,
            'members' => $members,
            'invitations' => app(Invitations::class)->pending($org->id),
            'domains' => app(DomainVerification::class)->forOrganization($org->id),
        ];
    }
}; ?>

<div class="space-y-6">
    <div>
        <a href="{{ route('environment.organizations') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Organizations</a>
        <div class="mt-2 flex items-center gap-3 flex-wrap">
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">{{ $org->name }}</h1>
            @php $statusVariant = match ($org->status) { OrganizationStatus::Active => 'badge-success', OrganizationStatus::Suspended => 'badge-warn', OrganizationStatus::Deleted => 'badge-danger', default => '' }; @endphp
            <span class="badge {{ $statusVariant }}">{{ $org->status->value }}</span>
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
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveDetails">Save changes</button>
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
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="members" class="w-5 h-5" /></div>
                    <h3>No members yet</h3>
                    <p>Add an existing user by email below to give them access to this organization.</p>
                </div>
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
            <button type="submit" class="btn btn-primary shrink-0" wire:loading.attr="disabled" wire:target="addMember">Add member</button>
        </form>
    </div>

    {{-- Invitations (for people who don't have an account yet) --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Invitations</p>
        <p class="mt-1 text-sm" style="color:var(--muted)">Invite someone by email — they join on their own by accepting the link.</p>
        <div class="mt-4 space-y-2">
            @forelse ($invitations as $inv)
                <div class="flex items-center gap-2 rounded-lg border px-3 py-2" style="border-color:var(--border)" wire:key="inv-{{ $inv->id }}">
                    <div class="min-w-0 flex-1">
                        <span class="block text-sm font-medium truncate">{{ $inv->email }}</span>
                        <span class="block text-xs" style="color:var(--faint)">{{ ucfirst($inv->role) }} · expires {{ $inv->expires_at?->diffForHumans() }}</span>
                    </div>
                    @php $invVariant = match ($inv->status->value) { 'accepted' => 'badge-success', 'pending' => 'badge-warn', 'revoked' => 'badge-danger', default => '' }; @endphp
                    <span class="badge {{ $invVariant }}">{{ $inv->status->value }}</span>
                    <button type="button" class="btn btn-ghost btn-sm shrink-0" style="color:var(--destructive)" wire:click="revokeInvitation('{{ $inv->id }}')" wire:confirm="Revoke this invitation?">Revoke</button>
                </div>
            @empty
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="members" class="w-5 h-5" /></div>
                    <h3>No pending invitations</h3>
                    <p>Invite someone by email below — they join on their own by accepting the link.</p>
                </div>
            @endforelse
        </div>
        <form wire:submit="invite" class="mt-4 grid sm:grid-cols-[1fr_auto_auto] gap-2 items-start">
            <div>
                <input wire:model="inviteEmail" type="email" class="input" placeholder="newteammate@example.com">
                @error('inviteEmail') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <select wire:model="inviteRole" class="select">
                <option value="member">Member</option>
                <option value="admin">Admin</option>
                <option value="owner">Owner</option>
            </select>
            <button type="submit" class="btn btn-primary shrink-0" wire:loading.attr="disabled" wire:target="invite">Send invite</button>
        </form>
    </div>

    {{-- Verified domains (drive SSO / directory matching) --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Domains</p>
        <p class="mt-1 text-sm" style="color:var(--muted)">Verify domains this organization owns so its users are matched to it for SSO.</p>
        <div class="mt-4 space-y-2">
            @forelse ($domains as $domain)
                <div class="rounded-lg border px-3 py-2" style="border-color:var(--border)" wire:key="domain-{{ $domain->id }}">
                    <div class="flex items-center gap-2">
                        <span class="min-w-0 flex-1 truncate text-sm font-medium mono">{{ $domain->domain }}</span>
                        @if ($domain->verified_at)
                            <span class="badge badge-success">Verified</span>
                            <button type="button" class="btn btn-ghost btn-sm shrink-0" wire:click="toggleCapture('{{ $domain->id }}')">{{ $domain->capture ? 'Capture on' : 'Capture off' }}</button>
                        @else
                            <span class="badge badge-warn">Pending</span>
                            <button type="button" class="btn btn-ghost btn-sm shrink-0" wire:click="verifyDomain('{{ $domain->id }}')">Verify</button>
                        @endif
                        <button type="button" class="btn btn-ghost btn-sm shrink-0" style="color:var(--destructive)" wire:click="removeDomain('{{ $domain->id }}')" wire:confirm="Remove this domain?">Remove</button>
                    </div>
                    @unless ($domain->verified_at)
                        <p class="mt-2 text-xs" style="color:var(--faint)">Add a DNS TXT record: <code class="mono select-all">{{ $domain->verification_token }}</code></p>
                    @endunless
                </div>
            @empty
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="directory" class="w-5 h-5" /></div>
                    <h3>No domains yet</h3>
                    <p>Add a domain this organization owns below, then verify it so its users are matched for SSO.</p>
                </div>
            @endforelse
        </div>
        <form wire:submit="addDomain" class="mt-4 grid sm:grid-cols-[1fr_auto] gap-2 items-start">
            <div>
                <input wire:model="newDomain" type="text" class="input mono" placeholder="acme.com">
                @error('newDomain') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary shrink-0" wire:loading.attr="disabled" wire:target="addDomain">Add domain</button>
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
