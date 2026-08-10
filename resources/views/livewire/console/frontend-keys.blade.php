<?php

use App\Platform\Console\ConsoleScope;
use Cbox\Id\FrontendApi\Contracts\PublishableKeys;
use Cbox\Id\FrontendApi\Enums\KeyMode;
use Cbox\Id\FrontendApi\Exceptions\UnusableOrigin;
use Cbox\Id\FrontendApi\Models\PublishableKey;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Publishable keys — the ones a browser is allowed to hold.
 *
 * THE PAGE IS MOSTLY ABOUT ORIGINS, and that is deliberate. The key itself is public and
 * uninteresting: it goes in a bundle and anybody can read it. What actually protects the
 * customer is the allow-list attached to it, so the origins are the primary control here
 * rather than a detail behind an edit link, and the page says why in plain words rather
 * than assuming somebody pasting a key into a JS file has read the security model.
 *
 * SHOWN IN FULL, ALWAYS. Every other credential screen in this console reveals a secret
 * once and then shows a prefix forever, because it is a secret. Doing that here would
 * teach the opposite of the truth — a person who cannot re-read their publishable key
 * concludes it must be sensitive, and starts handling it like one, which usually means
 * proxying it through their own server and losing the entire point.
 */
new #[Layout('components.layouts.console', ['title' => 'Frontend keys'])] class extends Component
{
    #[Validate('required|string|max:120')]
    public string $name = '';

    public string $mode = 'test';

    /** Newline-separated, because that is how a person pastes a list of URLs. */
    #[Validate('required|string|max:2000')]
    public string $origins = '';

    public bool $creating = false;

    public ?string $justCreated = null;

    public function boot(): void
    {
        app(ConsoleScope::class)->assertMayAdminister();
    }

    public function create(PublishableKeys $keys): void
    {
        app(ConsoleScope::class)->assertMayAdminister();

        $this->validate();

        $mode = KeyMode::tryFrom($this->mode) ?? KeyMode::Test;

        try {
            $key = $keys->issue($this->name, $mode, $this->parsedOrigins());
        } catch (UnusableOrigin $e) {
            // Surfaced on the field rather than as a toast: the person needs to see which
            // line they have to fix, and a toast disappears while they are still reading
            // it. The exception's message names the offending value.
            $this->addError('origins', $e->getMessage());

            return;
        }

        $this->justCreated = $key->key;
        $this->reset('name', 'origins', 'creating');

        $this->dispatch('toast', message: 'Key created. Paste it into your frontend — it is meant to be public.');
    }

    public function revoke(PublishableKeys $keys, string $id): void
    {
        app(ConsoleScope::class)->assertMayAdminister();

        $keys->revoke($id);

        $this->dispatch('toast', message: 'Key revoked. Pages still holding it stop working immediately.');
    }

    /**
     * One origin per line, blanks dropped.
     *
     * Nothing is normalized or validated here — that belongs to the store, which is the
     * only place that can refuse consistently whether the input came from this form, an
     * API call or a seeder.
     *
     * @return list<string>
     */
    private function parsedOrigins(): array
    {
        return array_values(array_filter(
            array_map(trim(...), preg_split('/\r\n|\r|\n/', $this->origins) ?: []),
            static fn (string $line): bool => $line !== '',
        ));
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        return [
            'keys' => PublishableKey::query()
                ->with('origins')
                ->orderByDesc('created_at')
                ->get(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Frontend keys"
                   subtitle="Public keys your own pages use to read their sign-in configuration — no server in the middle.">
        <x-slot:actions>
            <button wire:click="$toggle('creating')" class="btn btn-primary shrink-0">
                <x-icon name="plus" class="w-4 h-4" /> New key
            </button>
        </x-slot:actions>
    </x-page-header>

    @if ($justCreated !== null)
        {{-- Shown once prominently, and then permanently in the table below. Not a
             "copy it now, you will never see it again" banner: this key is meant to be
             public, and teaching people to treat it as a secret is how it ends up
             proxied through their server, which defeats the whole feature. --}}
        <div class="card p-4 mt-6" style="border-color:var(--accent-edge);background:var(--accent-soft)">
            <p class="text-sm font-medium">Your new key</p>
            <p class="mono text-[13px] mt-1 break-all">{{ $justCreated }}</p>
            <p class="text-xs mt-2" style="color:var(--muted)">
                Paste it straight into your frontend. It is safe in a bundle — it only works
                from the origins you listed.
            </p>
        </div>
    @endif

    @if ($creating)
        <form wire:submit="create" class="cbx-panel mt-6">
            <div class="cbx-panel-header"><h2 class="cbx-panel-title">New publishable key</h2></div>
            <div class="cbx-panel-body" style="display:flex;flex-direction:column;gap:16px">
                <div>
                    <label class="label" for="fk-name">Name</label>
                    <input id="fk-name" wire:model="name" type="text" class="input" placeholder="Marketing site">
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="label" for="fk-mode">Mode</label>
                    <select id="fk-mode" wire:model="mode" class="input">
                        <option value="test">Test — pk_test_…</option>
                        <option value="live">Live — pk_live_…</option>
                    </select>
                    <p class="text-xs mt-1" style="color:var(--muted)">
                        The mode is part of the key, so anyone reading a bundle can tell which
                        one is in it.
                    </p>
                </div>

                <div>
                    <label class="label" for="fk-origins">Allowed origins</label>
                    <textarea id="fk-origins" wire:model="origins" rows="4" class="input mono"
                              placeholder="https://acme.com&#10;https://www.acme.com&#10;http://localhost:3000"></textarea>
                    @error('origins') <p class="field-error">{{ $message }}</p> @enderror
                    <p class="text-xs mt-1" style="color:var(--muted)">
                        One per line. <strong>This is what protects the key</strong> — it is public,
                        so the list of sites allowed to use it is the whole control. Exact matches
                        only: <span class="mono">https://acme.com</span> does not cover
                        <span class="mono">https://www.acme.com</span>. Plain http is refused except
                        on localhost.
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">
                        <span class="spinner" wire:loading wire:target="create" aria-hidden="true"></span> Create key
                    </button>
                    <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
                </div>
        </form>
    @endif

    <p role="status" aria-live="polite" class="sr-only">{{ $keys->count() }} {{ \Illuminate\Support\Str::plural('key', $keys->count()) }}.</p>

    @if ($keys->isEmpty())
        <div class="cbx-empty mt-8">
            <div class="cbx-empty-icon"><x-icon name="clients" class="w-5 h-5" /></div>
            <h3>No frontend keys yet</h3>
            <p>Create one to let your own pages read their sign-in configuration directly, instead of routing it through your server.</p>
        </div>
    @else
        <div class="cbx-panel overflow-hidden mt-8">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Key</th>
                            <th scope="col">Allowed origins</th>
                            <th scope="col">Last used</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($keys as $key)
                            <tr @class(['opacity-60' => ! $key->isActive()])>
                                <td>
                                    {{ $key->name }}
                                    <span class="badge ml-1">{{ $key->mode->label() }}</span>
                                    @unless ($key->isActive())
                                        <span class="badge ml-1">Revoked</span>
                                    @endunless
                                </td>
                                <td class="mono text-[12px] break-all">{{ $key->key }}</td>
                                <td>
                                    @forelse ($key->origins as $origin)
                                        <span class="block mono text-[12px]">{{ $origin->origin }}</span>
                                    @empty
                                        {{-- A key with no origins works nowhere. Said plainly, because
                                             the symptom otherwise is a page that fails with a CORS
                                             error nobody can explain. --}}
                                        <span class="text-xs" style="color:var(--danger)">None — this key works nowhere</span>
                                    @endforelse
                                </td>
                                <td class="text-xs" style="color:var(--muted)">
                                    {{ $key->last_used_at?->diffForHumans() ?? 'Never' }}
                                </td>
                                <td class="text-right">
                                    @if ($key->isActive())
                                        <button wire:click="revoke('{{ $key->id }}')"
                                                wire:confirm="Revoke {{ $key->name }}? Any page still holding it stops working immediately."
                                                class="btn btn-ghost btn-sm">Revoke</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <p class="mt-4 text-xs" style="color:var(--faint)">
            A revoked key keeps its row so a key that turns up in a log later can still be
            identified as one you withdrew, and when.
        </p>
    @endif
</div>
