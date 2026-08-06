<?php

declare(strict_types=1);

use App\Platform\OrganizationCapabilities;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\PlatformRoot;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Identity platform › Organization settings. Management-only; deletion is deliberately not a
 * self-serve button (it would tear down live IdPs) and is handled as a support request for
 * now.
 */
new #[Layout('components.layouts.app', ['title' => 'Organization settings'])] class extends Component
{
    public string $name = '';

    public function mount(ConsoleScope $scope, Organizations $organizations): mixed
    {
        $organization = $this->acting($scope, $organizations);

        if ($organization === null || $scope->capabilities()?->canManageMembers() !== true) {
            return redirect()->route('projects');
        }

        $this->name = $organization->name;

        return null;
    }

    public function save(ConsoleScope $scope, Organizations $organizations): void
    {
        $organization = $this->acting($scope, $organizations);

        if ($organization === null || $scope->capabilities()?->canManageMembers() !== true) {
            return;
        }

        $this->validate(['name' => ['required', 'string', 'max:120']]);

        // Renamed on the model rather than through a contract verb: `Organizations` has no
        // rename(). The account plane's writer did, and it is the one verb of that interface
        // with no counterpart here — worth naming so a future reader does not assume it was
        // overlooked.
        //
        // IN THE PLATFORM ROOT, because the WRITE is guarded too: `BelongsToEnvironment`
        // refuses a cross-environment save outright, so a rename issued from any other host
        // raises rather than silently writing nowhere.
        app(PlatformRoot::class)->run(
            fn () => $organization->forceFill(['name' => trim($this->name)])->save(),
        );

        $this->dispatch('toast', message: 'Organization settings saved.');
    }

    /** The organization being administered, or null when there is none to act on. */
    private function acting(ConsoleScope $scope, Organizations $organizations): ?Organization
    {
        $id = $scope->organizationId();

        // IN THE PLATFORM ROOT: `organizations` is environment-owned, and read from
        // whatever host serves the console this finds nothing — so the guard below would
        // bounce the organization's own owner off their own settings page.
        return $id === null ? null : app(PlatformRoot::class)->run(fn () => $organizations->find($id));
    }
}; ?>

<div>
    <x-page-header title="Organization settings" subtitle="Manage the organization these identity providers belong to." />

    <form wire:submit="save" class="mt-6 rounded-xl border p-5" style="border-color:var(--border)">
        <label class="label" for="name">Account name</label>
        <p class="mt-1 text-sm" style="color:var(--muted)">Shown across the console.</p>
        <div class="mt-3 flex items-start gap-2">
            <div class="flex-1 max-w-sm">
                <input wire:model="name" id="name" type="text" class="input" placeholder="Acme Inc.">
                @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">Save</button>
        </div>
    </form>

    <div class="mt-4 rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Delete organization</p>
        <p class="mt-1 text-sm" style="color:var(--muted)">Deleting an organization tears down every project and environment it owns. To protect live IdPs this isn't self-serve — contact support to proceed.</p>
    </div>
</div>
