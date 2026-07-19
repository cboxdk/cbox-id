<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Organization settings — the workspace an admin manages. A user's own security
 * (password, 2FA, passkeys, sessions) lives in "My account" instead.
 */
new #[Layout('components.layouts.app', ['title' => 'Settings'])] class extends Component
{
    public string $orgName = '';

    public function mount(): void
    {
        // Org settings are an admin surface. A member who lands here (a bookmark, a
        // typed URL) belongs in their own security centre, not a read-only echo of
        // controls they can't use.
        if (! app(CurrentUser::class)->isAdmin()) {
            $this->redirect(route('account'), navigate: true);

            return;
        }

        $org = app(CurrentUser::class)->organization();
        $this->orgName = $org?->name ?? '';
    }

    public function rename(AuditLog $audit): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);

        $this->validate(['orgName' => ['required', 'string', 'max:120']]);

        $me = app(CurrentUser::class);
        $org = $me->organization();

        if ($org === null || trim($this->orgName) === $org->name) {
            return;
        }

        $from = $org->name;
        $org->forceFill(['name' => trim($this->orgName)])->save();

        $audit->record(AuditEvent::forUser('organization.renamed', $me->id(), $org->id, [
            'from' => $from,
            'to' => trim($this->orgName),
        ]));

        session()->flash('status', 'Organization name updated.');
    }

    public function with(): array
    {
        $me = app(CurrentUser::class);

        return ['me' => $me, 'org' => $me->organization()];
    }
}; ?>

<div class="space-y-6">
    <div class="cbx-page-header">
        <div>
            <p class="cbx-page-eyebrow">Organization</p>
            <h1 class="cbx-page-title">Settings</h1>
            <p class="cbx-page-desc">The workspace you administer. Manage your own security under
                <a href="{{ route('account') }}" class="underline" style="color:var(--accent)">My account</a>.</p>
        </div>
    </div>

    {{-- Organization --}}
    <section class="cbx-panel">
        <div class="cbx-panel-header">
            <div>
                <h3 class="cbx-panel-title">Organization</h3>
                <p class="cbx-panel-desc">The workspace you are currently signed in to.</p>
            </div>
        </div>
        <div class="cbx-panel-body">
            @if ($org)
                @if ($me->isAdmin())
                    <form wire:submit="rename" class="mb-4">
                        <label class="label" for="orgName">Name</label>
                        <div class="flex items-center gap-2">
                            <input wire:model="orgName" id="orgName" type="text" class="input" style="flex:1" maxlength="120">
                            <button type="submit" class="btn btn-primary shrink-0" wire:loading.attr="disabled" wire:target="rename">Rename</button>
                        </div>
                        @error('orgName') <p class="field-error">{{ $message }}</p> @enderror
                    </form>
                @endif
                <dl>
                    @unless ($me->isAdmin())
                        <div class="cbx-kv"><dt>Name</dt><dd class="prose">{{ $org->name }}</dd></div>
                    @endunless
                    <div class="cbx-kv"><dt>Slug</dt><dd>{{ $org->slug }}</dd></div>
                    <div class="cbx-kv"><dt>Type</dt><dd class="prose"><span class="badge">{{ ucfirst($org->type->value) }}</span></dd></div>
                    <div class="cbx-kv"><dt>Organization ID</dt><dd>{{ $org->id }}</dd></div>
                </dl>
            @else
                <p class="text-sm" style="color:var(--faint)">No organization is associated with this session.</p>
            @endif
        </div>
    </section>

    {{-- Login branding --}}
    @if ($me->isAdmin() && $org)
        <section class="cbx-panel">
            <div class="cbx-panel-header">
                <div>
                    <h3 class="cbx-panel-title">Login branding</h3>
                    <p class="cbx-panel-desc">Theme your organization's sign-in page. Your team signs in at
                        <a href="{{ route('login.branded', $org->slug) }}" class="mono underline" style="color:var(--accent)">/o/{{ $org->slug }}/login</a>.</p>
                </div>
            </div>
            <div class="cbx-panel-body">
                @php $ap = \App\Platform\Appearance\Appearance::fromSettings(is_array($org?->settings) ? $org->settings : []); @endphp
                <div class="flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="grid grid-cols-2 grid-rows-2 w-10 h-10 rounded-lg overflow-hidden shrink-0" style="border:1px solid var(--border)" aria-hidden="true">
                            <span style="background:{{ $ap->light['background'] }}"></span>
                            <span style="background:{{ $ap->light['primary'] }}"></span>
                            <span style="background:{{ $ap->dark['background'] }}"></span>
                            <span style="background:{{ $ap->dark['primary'] }}"></span>
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-medium">{{ \App\Platform\Appearance\ThemePresets::all()[$ap->preset]['label'] ?? 'Custom' }} theme</p>
                            <p class="text-xs" style="color:var(--muted-foreground)">Presets, colours, corners &amp; type — edited with a live preview.</p>
                        </div>
                    </div>
                    <a href="{{ route('appearance') }}" class="btn btn-secondary shrink-0">Open editor <x-icon name="chevron" class="w-4 h-4" style="transform:rotate(-90deg)" /></a>
                </div>
            </div>
        </section>
    @endif
</div>
