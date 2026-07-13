<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
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

    public function register(Directories $directories): void
    {
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

    public function with(): array
    {
        return [
            'me' => app(CurrentUser::class),
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

    private function authorizeAdmin(): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);
    }
}; ?>

<div>
    <x-page-header title="Directory sync" subtitle="Provision and de-provision users automatically over SCIM.">
        <x-slot:actions>
            @if ($me->isAdmin())
                <button wire:click="$toggle('creating')" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> New directory</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="card p-5 mb-5">
        <div class="flex items-center gap-2 text-sm font-semibold"><x-icon name="directory" class="w-4 h-4" /> SCIM endpoint</div>
        <p class="mt-2 text-xs" style="color:var(--muted)">Point your identity provider (Okta, Microsoft Entra) at this base URL and authenticate with a directory's bearer token.</p>
        <p class="mt-3 mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ url('/scim/v2') }}</p>
    </div>

    @if ($newToken && $me->isAdmin())
        <div class="card p-5 mb-5" style="border-color:color-mix(in srgb, var(--warn) 40%, transparent)">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 font-semibold"><x-icon name="key" class="w-4 h-4" /> Bearer token for “{{ $newTokenName }}”</div>
                    <p class="mt-1 text-sm" style="color:var(--warn)">Copy this now — it is shown only once and cannot be retrieved again.</p>
                </div>
                <button wire:click="dismissToken" class="btn btn-ghost" style="padding:0.35rem 0.6rem;font-size:0.8rem">Done</button>
            </div>
            <p class="mt-3 mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $newToken }}</p>
        </div>
    @endif

    @if ($creating && $me->isAdmin())
        <form wire:submit="register" class="card p-4 mb-5 flex flex-wrap items-end gap-3">
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
                                <p class="text-xs mono truncate" style="color:var(--faint)">{{ $dir->id }}</p>
                            </td>
                            <td>
                                @if ($dir->status === \Cbox\Id\Directory\Enums\DirectoryStatus::Active)
                                    <span class="badge badge-success">Active</span>
                                @else
                                    <span class="badge badge-warn">Paused</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="text-center py-10" style="color:var(--faint)">No directories connected yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
