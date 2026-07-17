<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Organization\Contracts\Organizations;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Organization settings — the workspace an admin manages. A user's own security
 * (password, 2FA, passkeys, sessions) lives in "My account" instead.
 */
new #[Layout('components.layouts.app', ['title' => 'Settings'])] class extends Component
{
    public string $orgName = '';

    public string $brandColor = '';

    public string $brandLogoUrl = '';

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
        $settings = $org?->settings ?? [];
        $this->brandColor = is_string($settings['brand_color'] ?? null) ? $settings['brand_color'] : '';
        $this->brandLogoUrl = is_string($settings['brand_logo_url'] ?? null) ? $settings['brand_logo_url'] : '';
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

    public function saveBranding(Organizations $organizations): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);

        $this->validate([
            'brandColor' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'brandLogoUrl' => ['nullable', 'url', 'max:500'],
        ], [
            'brandColor.regex' => 'Use a 6-digit hex colour, e.g. #4f46e5.',
        ]);

        $orgId = app(CurrentUser::class)->organizationId();

        if ($orgId !== null) {
            $organizations->updateSettings($orgId, [
                'brand_color' => $this->brandColor ?: null,
                'brand_logo_url' => $this->brandLogoUrl ?: null,
            ]);
            session()->flash('status', 'Branding saved.');
        }
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
                <form wire:submit="saveBranding" class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="brandColor">Primary colour</label>
                        <div class="flex items-center gap-2">
                            <input wire:model.live.debounce.400ms="brandColor" id="brandColor" type="text" class="input mono" placeholder="#4f46e5" style="flex:1">
                            <span class="rounded-md shrink-0" style="width:2.4rem;height:2.4rem;border:1px solid var(--border);background:{{ preg_match('/^#[0-9a-fA-F]{6}$/', $brandColor) ? $brandColor : 'var(--surface-2)' }}"></span>
                        </div>
                        @error('brandColor') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="brandLogoUrl">Logo URL (https)</label>
                        <input wire:model="brandLogoUrl" id="brandLogoUrl" type="url" class="input" placeholder="https://acme.com/logo.svg">
                        @error('brandLogoUrl') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Save branding</button>
                    </div>
                </form>
            </div>
        </section>
    @endif
</div>
