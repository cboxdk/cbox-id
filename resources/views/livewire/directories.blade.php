<?php

declare(strict_types=1);

use App\Platform\AdminPortal;
use App\Platform\CurrentUser;
use App\Platform\Entitlements;
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Directory\Models\Directory;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'Directory sync'])] class extends Component
{
    public bool $creating = false;

    #[Validate('required|string|max:120')]
    public string $name = '';

    /** One-time SCIM bearer token, shown once right after registration. */
    public ?string $newToken = null;

    public ?string $newTokenName = null;

    /** The Admin Portal setup URL, shown to the admin exactly once after minting. */
    public ?string $portalUrl = null;

    public function register(Directories $directories): void
    {
        $this->guardEntitled();
        $this->authorizeAdmin();
        $this->validate();

        $registered = $directories->register($this->orgId(), $this->name);

        $this->newToken = $registered->token;
        $this->newTokenName = $registered->directory->name;
        $this->reset('creating', 'name');
    }

    public function dismissToken(): void
    {
        $this->reset('newToken', 'newTokenName');
    }

    /**
     * Mint a single-use Admin Portal link and reveal its URL once, so the admin
     * can hand SCIM setup to an external IT admin without granting them an account.
     */
    public function invite(AdminPortal $portal): void
    {
        $this->guardEntitled();
        $this->authorizeAdmin();

        $token = $portal->generate($this->orgId(), 'scim', app(CurrentUser::class)->id());
        $this->portalUrl = route('portal.enter', $token);
    }

    public function with(): array
    {
        return [
            'me' => app(CurrentUser::class),
            'entitled' => app(Entitlements::class)->entitled($this->orgId(), 'scim'),
            'directories' => Directory::query()
                ->where('organization_id', $this->orgId())
                ->orderByDesc('created_at')
                ->get(),
        ];
    }

    private function orgId(): string
    {
        return app(CurrentUser::class)->organizationId() ?? '';
    }

    public function mount(): void
    {
        // Read gate: these pages expose org-wide config (client secrets shown
        // once, SSO connection settings, directory tokens, audit) — admins only.
        $this->authorizeAdmin();
    }

    private function authorizeAdmin(): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);
    }

    /**
     * Deny-by-default entitlement gate for every mutating action. Runs BEFORE the
     * admin check, so a direct Livewire call from a non-entitled org is refused
     * even though the (upsell) screen itself is reachable.
     */
    private function guardEntitled(): void
    {
        abort_unless(app(Entitlements::class)->entitled($this->orgId(), 'scim'), 403);
    }
}; ?>

<div>
    <div class="cbx-page-header">
        <div>
            <p class="cbx-page-eyebrow">Provisioning</p>
            <h1 class="cbx-page-title">Directory sync</h1>
            <p class="cbx-page-desc">Provision and de-provision users automatically over SCIM.</p>
        </div>
        @if ($me->isAdmin() && $entitled)
            <div class="flex items-center gap-2">
                <button wire:click="invite" class="btn btn-ghost"><x-icon name="members" class="w-4 h-4" /> Invite your IT admin</button>
                <button wire:click="$toggle('creating')" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> New directory</button>
            </div>
        @endif
    </div>

    @if (! $entitled)
        <div class="card mt-8">
            <div class="cbx-empty">
                <div class="cbx-empty-icon"><x-icon name="directory" class="w-5 h-5" /></div>
                <h3>SCIM directory sync is an Enterprise feature</h3>
                <p>
                    Automatic user provisioning and de-provisioning over SCIM 2.0 is
                    available on the Enterprise plan. Contact your account team to enable
                    it for this organization.
                </p>
            </div>
        </div>
    @else

    <div class="mt-8 space-y-6">
    <div class="cbx-panel">
        <div class="cbx-panel-header">
            <div>
                <div class="cbx-panel-title flex items-center gap-2"><x-icon name="directory" class="w-4 h-4" /> SCIM endpoint</div>
                <p class="cbx-panel-desc">Point your identity provider (Okta, Microsoft Entra) at this base URL and authenticate with a directory's bearer token.</p>
            </div>
        </div>
        <div class="cbx-panel-body">
            <p class="mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--secondary);border:1px solid var(--border)">{{ url('/scim/v2') }}</p>
        </div>
    </div>

    @if ($portalUrl && $me->isAdmin())
        <div class="card p-5" style="border-color:color-mix(in oklch, var(--accent) 40%, transparent)">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 font-semibold"><x-icon name="members" class="w-4 h-4" /> Setup link for your IT admin</div>
                    <p class="mt-1 text-sm" style="color:var(--muted-foreground)">Send this single-use link to whoever configures your identity provider. It expires soon and works without an account. Copy it now — it is shown only once.</p>
                </div>
                <button wire:click="$set('portalUrl', null)" class="btn btn-ghost btn-sm">Done</button>
            </div>
            <p class="mt-3 mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--secondary);border:1px solid var(--border)">{{ $portalUrl }}</p>
        </div>
    @endif

    @if ($newToken && $me->isAdmin())
        <div class="card p-5" style="border-color:color-mix(in oklch, var(--warning) 40%, transparent)">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 font-semibold"><x-icon name="key" class="w-4 h-4" /> Bearer token for “{{ $newTokenName }}”</div>
                    <p class="mt-1 text-sm" style="color:var(--warning)">Copy this now — it is shown only once and cannot be retrieved again.</p>
                </div>
                <button wire:click="dismissToken" class="btn btn-ghost btn-sm">Done</button>
            </div>
            <p class="mt-3 mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--secondary);border:1px solid var(--border)">{{ $newToken }}</p>
        </div>
    @endif

    @if ($creating && $me->isAdmin())
        <form wire:submit="register" class="card p-4 flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[14rem]">
                <label class="label" for="name">Directory name</label>
                <input wire:model="name" id="name" type="text" class="input" placeholder="Acme Okta SCIM" autofocus>
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Register directory</button>
            <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
        </form>
    @endif

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr><th scope="col">Directory</th><th scope="col">Status</th></tr>
                </thead>
                <tbody>
                    @forelse ($directories as $dir)
                        <tr>
                            <td>
                                <p class="font-medium truncate">{{ $dir->name }}</p>
                                <p class="text-xs mono truncate" style="color:var(--muted-foreground)">{{ $dir->id }}</p>
                            </td>
                            <td>
                                @if ($dir->status === \Cbox\Id\Directory\Enums\DirectoryStatus::Active)
                                    <span class="cbx-pill cbx-pill--success"><span class="dot"></span> Active</span>
                                @else
                                    <span class="cbx-pill cbx-pill--warning"><span class="dot"></span> Paused</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="text-center py-10" style="color:var(--muted-foreground)">No directories connected yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    </div>
    @endif
</div>
