<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\OAuthServer\Contracts\DeviceAuthorization;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'Connect a device'])] class extends Component
{
    #[Validate('required|string')]
    public string $userCode = '';

    public ?string $outcome = null; // 'approved' | 'denied' | 'invalid'

    public function mount(): void
    {
        $code = request()->query('user_code');
        if (is_string($code)) {
            $this->userCode = strtoupper($code);
        }
    }

    public function approve(DeviceAuthorization $devices, CurrentUser $me): void
    {
        $this->validate();

        $ok = $devices->approve(trim($this->userCode), $me->id(), $me->organizationId());
        $this->outcome = $ok ? 'approved' : 'invalid';
    }

    public function deny(DeviceAuthorization $devices): void
    {
        $this->validate();

        $devices->deny(trim($this->userCode));
        $this->outcome = 'denied';
    }
}; ?>

<div class="max-w-md">
    <div class="cbx-page-header mb-8">
        <div>
            <h1 class="cbx-page-title">Connect a device</h1>
            <p class="cbx-page-desc">Enter the code shown on your device to link it to your account.</p>
        </div>
    </div>

    @if ($outcome === 'approved')
        <div role="status" class="card p-5 flex items-start gap-3" style="border-color:color-mix(in srgb,var(--success) 30%,transparent)">
            <x-icon name="check" class="w-5 h-5 mt-0.5" style="color:var(--success)" />
            <div>
                <p class="font-medium">Device connected</p>
                <p class="text-sm" style="color:var(--muted)">You can return to your device — it's now signed in.</p>
            </div>
        </div>
    @elseif ($outcome === 'denied')
        <div role="status" class="card p-5 text-sm" style="color:var(--muted)">Request denied. The device was not connected.</div>
    @else
        <form wire:submit="approve" class="card p-5 space-y-4">
            @if ($outcome === 'invalid')
                <div role="alert" class="rounded-lg px-3.5 py-2.5 text-sm" style="background:var(--danger-soft);color:var(--danger)">
                    That code is invalid or has expired. Check the code on your device and try again.
                </div>
            @endif

            <div>
                <label class="label" for="userCode">Device code</label>
                <input wire:model="userCode" id="userCode" name="userCode" type="text" autocomplete="one-time-code"
                       autocapitalize="characters" spellcheck="false"
                       class="input input-lg mono text-center tracking-[0.3em]" placeholder="XXXX-XXXX"
                       @error('userCode') aria-invalid="true" aria-describedby="userCode-error" @enderror>
                @error('userCode') <p class="field-error" id="userCode-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div class="flex gap-2.5">
                <button type="submit" class="btn btn-primary btn-lg flex-1">Approve</button>
                <button type="button" wire:click="deny" class="btn btn-ghost btn-lg">Deny</button>
            </div>
            <p class="text-xs" style="color:var(--faint)">Only approve if you just started signing in on a device you own.</p>
        </form>
    @endif
</div>
