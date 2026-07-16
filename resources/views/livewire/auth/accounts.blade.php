<?php

use App\Platform\PlatformAuth;
use Illuminate\Http\RedirectResponse;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * The account chooser — Notion/Slack style. Lists the accounts signed in on this
 * browser, switches between them without re-authenticating, or adds another. Used
 * both from the console and as the target of an OAuth `prompt=select_account`.
 */
new #[Layout('components.layouts.auth', ['title' => 'Choose account'])] class extends Component
{
    /**
     * @return list<array{subject_id: string, name: string, email: ?string, organization_id: ?string, active: bool}>
     */
    public function accounts(): array
    {
        return app(PlatformAuth::class)->accounts();
    }

    public function switchTo(string $subjectId): ?RedirectResponse
    {
        if (app(PlatformAuth::class)->switchTo(request(), $subjectId)) {
            // Resume wherever the flow was headed (e.g. the OAuth authorize request).
            return redirect()->intended(route('dashboard'));
        }

        return null;
    }

    public function add(): RedirectResponse
    {
        return redirect()->route('accounts.add');
    }
}; ?>

<div class="w-full max-w-sm mx-auto">
    <h1 class="font-semibold tracking-tight text-2xl">Choose an account</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">Signed in on this device. Pick one, or add another.</p>

    <ul class="mt-6 flex flex-col gap-2">
        @foreach ($this->accounts() as $account)
            <li>
                <button type="button" wire:click="switchTo('{{ $account['subject_id'] }}')"
                    class="w-full flex items-center gap-3 rounded-xl border px-3.5 py-3 text-left transition hover:border-[var(--accent)]"
                    style="border-color:var(--border);background:var(--surface)">
                    <span class="cbx-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($account['name'], 0, 1)) }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{ $account['name'] }}</span>
                        @if ($account['email'])
                            <span class="block truncate text-sm" style="color:var(--muted)">{{ $account['email'] }}</span>
                        @endif
                    </span>
                    @if ($account['active'])
                        <span class="text-xs font-medium" style="color:var(--accent)">Active</span>
                    @endif
                </button>
            </li>
        @endforeach
    </ul>

    <button type="button" wire:click="add"
        class="mt-3 w-full flex items-center justify-center gap-2 rounded-xl border border-dashed px-3.5 py-3 text-sm font-medium transition hover:border-[var(--accent)]"
        style="border-color:var(--border)">
        <x-icon name="plus" class="w-4 h-4" /> Add another account
    </button>
</div>
