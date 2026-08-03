<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use Illuminate\Auth\Access\AuthorizationException;
use Cbox\Id\Governance\Contracts\AccessReviews;
use Cbox\Id\Organization\Models\Organization;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Environment control plane › Access reviews › New. A dedicated, deep-linkable page
 * that opens a certification campaign: it snapshots the selected organization's direct
 * role assignments and memberships as pending items to certify or revoke, then routes
 * straight to the new campaign's detail page.
 *
 * The organization is environment-owned, so the snapshot resolves ONLY within this
 * environment. The acting reviewer is the env-admin account member (a fourth-plane
 * identity), resolved from the env-admin session — not a subject inside the tenant.
 */
new #[Layout('components.layouts.console', ['title' => 'New access review'])] class extends Component
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

    /**
     * Open a campaign: snapshot the selected organization's direct role assignments
     * and memberships as pending items, then route to its detail page.
     */
    public function open(AccessReviews $reviews): mixed
    {
        $this->validate();

        // The organization comes from the scope, not from a field on this form. The
        // environment plane picks it in the console chrome; the organization plane never
        // picks at all. A field here was the second place the answer lived, and the two
        // planes validated it differently.
        try {
            $organizationId = app(ConsoleScope::class)->requireOrganizationId();
        } catch (AuthorizationException $e) {
            $this->addError('name', $e->getMessage());

            return null;
        }

        $campaign = $reviews->open(
            $organizationId,
            $this->name,
            now()->addWeek(),
            createdBy: $this->reviewerId(),
        );

        $this->dispatch('toast', message: 'Access review "'.$campaign->name.'" opened with '.count($reviews->itemsFor($campaign->id)).' item(s).');

        return $this->redirectRoute(app(ConsoleScope::class)->routeName('governance.show'), ['campaign' => $campaign->id], navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            'organizations' => Organization::query()->orderBy('name')->get(),
        ];
    }

    /** The acting reviewer: the env-admin account member for this environment. */
    /** @see ConsoleScope::actorId() — the subject, on either plane, so the trail reads. */
    private function reviewerId(): string
    {
        return app(ConsoleScope::class)->actorId();
    }
}; ?>

<div>
    <a href="{{ route($scopeRoute('governance')) }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Access reviews</a>
    <x-page-header class="mt-2" title="New access review" subtitle="Snapshots every current role assignment and membership in the selected organization as items to certify or revoke." />

    <form wire:submit="open" class="mt-6 max-w-xl rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
        <div>
            <label class="label" for="name">Review name</label>
            <input wire:model="name" id="name" type="text" class="input" placeholder="Q3 access review" autofocus>
            @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="open">Open review</button>
            <a href="{{ route($scopeRoute('governance')) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
