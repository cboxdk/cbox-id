<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use App\Platform\Console\VaultScope;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Console › Token vault (list) — one component, both planes. The downstream credential
 * store: the API keys and tokens this organization's apps and agents present to third
 * parties (OpenAI, GitHub, …). Each row deep-links to its own detail page where rotation,
 * grants and revocation live; creation is a dedicated page. The list only lists, and a
 * sealed value is NEVER echoed here.
 *
 * THE LAST UNMERGED PAIR, and the one where the drift was a security hole rather than a
 * missing feature. The organization plane's page was gated by `sudo`; the environment
 * plane's three pages by nothing at all — so the identical rotate, grant and revoke
 * actions demanded a fresh password from a tenant admin and none whatsoever from the
 * administrator who can act on EVERY tenant. Both planes now carry a step-up
 * ({@see \App\Http\Middleware\RequireEnvironmentSudo} is the environment plane's, which
 * did not exist), and there is one component, so a gate cannot be added to one plane and
 * forgotten on the other again.
 *
 * The routable index → new → detail shape wins over the organization plane's single page
 * with its inline forms, for the reason the other merges gave: a stored credential has a
 * lifecycle worth deep-linking to.
 */
new #[Layout('components.layouts.console', ['title' => 'Token vault'])] class extends Component
{
    use WithPagination;

    /**
     * Second layer. The route's session gate (`platform.auth` on one plane, `env.admin`
     * on the other) proves a session exists; neither is a ROLE check, and the URL is
     * typeable. boot() rather than mount(): only boot() runs on each Livewire action.
     */
    public function boot(): void
    {
        app(ConsoleScope::class)->assertMayAdminister();
    }

    #[Url(as: 'q')]
    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        // Through the scope, not through a hand-written where: the list and the detail
        // page's mutations must be bounded by the SAME owner, or a row can be listed
        // that no button on it can act on (or, as it was, the reverse).
        $query = app(VaultScope::class)->secrets()->orderByDesc('id');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('name', 'like', "%{$term}%");
        }

        return [
            'secrets' => $query->paginate(25),
            'environmentWide' => app(ConsoleScope::class)->organizationId() === null,
        ];
    }
}; ?>

<div>
    <x-page-header title="Token vault" :help="\App\Platform\Help\HelpTopic::TokenVault"
                   subtitle="API keys your apps and agents present to other services. Each value is sealed at rest, handed only to the apps you grant, and never shown again after you store it.">
        <x-slot:actions>
            <a href="{{ route(app(\App\Platform\Console\ConsoleScope::class)->routeName('vault.create')) }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New secret</a>
        </x-slot:actions>
    </x-page-header>

    @if ($environmentWide)
        {{-- Only an environment administrator with no organization chosen sees this: the
             environment's OWN unowned secrets, which is a real and separate set — not a
             wider view of the tenants'. Said out loud, because "the vault" meaning two
             different collections depending on a picker is exactly the kind of thing an
             administrator should not have to infer. --}}
        <p class="mt-4 text-sm" style="color:var(--muted)">Showing this environment's own secrets. Choose an organization above to manage that organization's.</p>
    @endif

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by name" aria-label="Search secrets">
        {{-- SC 4.1.3: the list re-renders on a debounced keystroke with no focus
             change, so the result count is the only thing that can report the filter
             narrowed to nothing. --}}
        <p role="status" aria-live="polite" class="sr-only">{{ $secrets->total() }} {{ \Illuminate\Support\Str::plural('secret', $secrets->total()) }} found.</p>
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($secrets as $s)
            <a href="{{ route(app(\App\Platform\Console\ConsoleScope::class)->routeName('vault.show'), $s->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate">{{ $s->name }}</span>
                    <p class="text-xs truncate mono" style="color:var(--faint)">{{ $s->provider }}</p>
                </div>
                @if ($s->isRevoked())
                    <span class="badge badge-danger">Revoked</span>
                @elseif ($s->isExpired())
                    <span class="badge badge-warn">Expired</span>
                @else
                    <span class="badge badge-success">Active</span>
                @endif
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            @if (trim($search) !== '')
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="key" class="w-5 h-5" /></div>
                    <h3>No matching secrets</h3>
                    <p>No secret matches "{{ $search }}". Try a different name.</p>
                </div>
            @else
                <div class="px-4 py-6">
                    <x-empty-state icon="key" title="No secrets stored yet"
                                   :help="\App\Platform\Help\HelpTopic::TokenVault"
                                   body="Every API key sitting in an app’s config or environment file is one you cannot rotate centrally or take away in a hurry. Stored here, it is encrypted, granted per app, and revocable in one click."
                                   :steps="[
                                       'Store the provider’s API key — you will not see it again afterwards.',
                                       'Grant the specific apps that may use it; nothing else can read it.',
                                       'Rotate it here when the provider issues a new one — the apps keep working, unchanged.',
                                   ]" />
                </div>
            @endif
        @endforelse
    </div>

    <div class="mt-4">{{ $secrets->links() }}</div>
</div>
