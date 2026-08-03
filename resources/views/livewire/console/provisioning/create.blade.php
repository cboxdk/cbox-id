<?php

declare(strict_types=1);

use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\Provisioning\Contracts\ProvisioningConnections;
use Cbox\Id\Provisioning\Enums\AuthScheme;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Console › Sync users out › New. A dedicated, deep-linkable create page for
 * registering a downstream SCIM target. The secret is sealed at rest and never shown
 * again. On success we route straight to the new connection's detail page.
 *
 * One component, both planes: the organization plane registered from an inline form on
 * the list with the organization implied, the environment plane from this page with an
 * organization picker on the form. The picker is gone — the console chrome owns which
 * organization is being administered, and a second copy of that answer on the form is
 * how the two planes came to validate it differently.
 *
 * What the picker also carried was its "All organizations" option, which is NOT an
 * organization: it registers an environment-wide connection (organization_id null)
 * covering every subject in the environment. That is a platform capability rather than
 * something a tenant may ask for — {@see Cbox\Id\Provisioning\DatabaseProvisioningConnections}
 * confines a tenant-owned connection to its owner for exactly this reason — so it
 * survives as its own explicit choice, offered and honoured on the environment plane
 * only.
 */
new #[Layout('components.layouts.console', ['title' => 'New outbound connection'])] class extends Component
{
    /**
     * Second layer. The route's `env.admin` middleware is the primary gate and IS
     * re-run on Livewire actions (PersistentMiddlewareTest holds that), but this
     * console previously had NO in-component authorization at all — so when that
     * middleware was missing from the persistent list, every action here answered
     * unauthenticated. boot() rather than mount(): only boot() runs on each action.
     */
    public function boot(): void
    {
        app(ConsoleScope::class)->assertMayAdminister();
    }

    #[Validate('required|string|max:190')]
    public string $name = '';

    #[Validate('required|url|max:500')]
    public string $baseUrl = '';

    /**
     * Cover every subject in the environment instead of one organization's members.
     * Offered on the environment plane only — and refused there and then if it arrives
     * from anywhere else, because a Livewire property is client-settable and a control
     * that is merely unrendered is not a control that is enforced.
     */
    public bool $environmentWide = false;

    #[Validate('required|string|in:bearer,oauth2_client_credentials')]
    public string $scheme = 'bearer';

    #[Validate('required|string|max:4096')]
    public string $secret = '';

    /**
     * Register a downstream SCIM target, then route to its detail page.
     */
    public function create(ProvisioningConnections $connections): mixed
    {
        $this->validate();

        try {
            $organizationId = $this->targetOrganizationId();
        } catch (AuthorizationException $e) {
            // Reported on the name field rather than thrown: on the environment plane
            // "you have not picked an organization yet" is an ordinary state of the
            // console, not a failure, and the form must survive to be resubmitted.
            $this->addError('name', $e->getMessage());

            return null;
        }

        $model = $connections->register(
            $organizationId,
            $this->name,
            $this->baseUrl,
            AuthScheme::from($this->scheme),
            $this->secret,
        )->connection;

        $this->dispatch('toast', message: 'Provisioning connection registered.');

        return $this->redirectRoute(app(ConsoleScope::class)->routeName('provisioning.show'), ['sync' => $model->id], navigate: true);
    }

    /**
     * The organization this connection provisions, or null for environment-wide.
     *
     * @throws AuthorizationException when no organization is resolved
     */
    private function targetOrganizationId(): ?string
    {
        $scope = app(ConsoleScope::class);

        if (! $this->environmentWide) {
            // The organization comes from the scope, not from a field on this form.
            return $scope->requireOrganizationId();
        }

        // An environment-wide connection receives every subject in the environment —
        // every tenant's people, over SCIM, to a target this administrator names. A
        // tenant administrator may never mint one, and "the checkbox is not rendered for
        // them" is not the guard: the property is client-settable.
        abort_unless($scope->plane() === ConsolePlane::Environment, 403);

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            // The one branch a page is allowed to make on the plane: whether the
            // administrator acts on several organizations or implicitly on their own.
            'mayScopeEnvironmentWide' => app(ConsoleScope::class)->plane()->choosesOrganization(),
        ];
    }
}; ?>

<div>
    <a href="{{ route($scopeRoute('provisioning')) }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Sync users out</a>
    <h1 class="mt-2 font-semibold tracking-tight" style="font-size:1.5rem">New outbound connection</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">Register a downstream app to provision users out to over its SCIM 2.0 endpoint.</p>

    <form wire:submit="create" class="mt-6 max-w-xl rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
        <div>
            <label class="label" for="name">Name</label>
            <input @error('name') aria-invalid="true" aria-describedby="name-error" @enderror wire:model="name" id="name" type="text" class="input" placeholder="Downstream app" autofocus>
            @error('name') <p id="name-error" class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="label" for="baseUrl">SCIM base URL</label>
            <input @error('baseUrl') aria-invalid="true" aria-describedby="baseUrl-error" @enderror wire:model="baseUrl" id="baseUrl" type="url" class="input mono" placeholder="https://app.example.com/scim/v2">
            @error('baseUrl') <p id="baseUrl-error" class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        @if ($mayScopeEnvironmentWide)
            <div>
                <label class="flex items-start gap-2" for="environmentWide">
                    <input wire:model="environmentWide" id="environmentWide" type="checkbox" class="mt-1">
                    <span>
                        <span class="label">Environment-wide</span>
                        <span class="block text-xs" style="color:var(--faint)">Provision every subject in this environment, not just the organization selected in the bar above.</span>
                    </span>
                </label>
            </div>
        @endif
        <div>
            <label class="label" for="scheme">Auth scheme</label>
            <select wire:model="scheme" id="scheme" class="select">
                <option value="bearer">Bearer token</option>
                <option value="oauth2_client_credentials">OAuth2 client credentials</option>
            </select>
            @error('scheme') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="label" for="secret">Secret</label>
            <input wire:model="secret" id="secret" type="password" class="input" placeholder="Bearer token or OAuth client secret" autocomplete="new-password">
            <p class="mt-1 text-xs" style="color:var(--faint)">Sealed at rest and never shown again. Supply the downstream app's bearer token or OAuth client secret.</p>
            @error('secret') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">Register connection</button>
            <a href="{{ route($scopeRoute('provisioning')) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
