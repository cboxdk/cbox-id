<?php

use Cbox\Id\Whitelabel\Assets\BrandAssetStore;
use Cbox\Id\Whitelabel\Contracts\BrandProfiles;
use Cbox\Id\Whitelabel\CustomDomain\Exceptions\InvalidCustomDomain;
use Cbox\Id\Whitelabel\CustomDomain\ManageCustomDomain;
use Cbox\Id\Whitelabel\Models\BrandProfile;
use Cbox\Id\Whitelabel\Support\PaletteTokens;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app', ['title' => 'Branding'])] class extends Component
{
    use WithFileUploads;

    /** @var array<string, string> */
    public array $palette = [];

    public string $appName = '';

    public string $emailFromName = '';

    public string $emailTemplate = '';

    public ?string $logoUrl = null;

    public ?string $faviconUrl = null;

    public string $customDomain = '';

    public ?string $currentDomain = null;

    public $logo = null;

    public $favicon = null;

    public function mount(): void
    {
        $profile = app(BrandProfiles::class)->forEnvironment();

        foreach (PaletteTokens::TOKENS as $token) {
            $this->palette[$token] = is_string($profile?->palette[$token] ?? null) ? $profile->palette[$token] : '';
        }

        $this->appName = (string) ($profile?->app_name ?? '');
        $this->emailFromName = (string) ($profile?->email_from_name ?? '');
        $this->emailTemplate = is_string($profile?->email_templates['welcome'] ?? null) ? $profile->email_templates['welcome'] : '';
        $this->logoUrl = $profile?->logo_url;
        $this->faviconUrl = $profile?->favicon_url;
        $this->currentDomain = app(ManageCustomDomain::class)->current();
    }

    public function save(): void
    {
        $clean = [];
        foreach (PaletteTokens::TOKENS as $token) {
            $value = trim($this->palette[$token] ?? '');
            if ($value === '') {
                continue;
            }
            if (! PaletteTokens::isValidColor($value)) {
                $this->addError('palette.'.$token, 'Use a hex (#0a2540) or oklch(...) colour.');

                return;
            }
            $clean[$token] = $value;
        }

        $profiles = app(BrandProfiles::class);
        $assets = app(BrandAssetStore::class);
        $profile = $profiles->forEnvironment() ?? new BrandProfile;

        if ($this->logo !== null) {
            $assets->forget($profile->logo_url);
            $this->logoUrl = $assets->put('logo', $this->logo);
        }

        if ($this->favicon !== null) {
            $assets->forget($profile->favicon_url);
            $this->faviconUrl = $assets->put('favicon', $this->favicon);
        }

        $templates = $profile->email_templates;
        $templates['welcome'] = $this->emailTemplate;

        $profile->fill([
            'palette' => $clean,
            'app_name' => $this->appName !== '' ? $this->appName : null,
            'email_from_name' => $this->emailFromName !== '' ? $this->emailFromName : null,
            'email_templates' => $templates,
            'logo_url' => $this->logoUrl,
            'favicon_url' => $this->faviconUrl,
        ]);

        $profiles->save($profile);

        $this->logo = null;
        $this->favicon = null;
        session()->flash('status', 'Branding saved.');
    }

    public function saveDomain(): void
    {
        try {
            $this->currentDomain = app(ManageCustomDomain::class)->set($this->customDomain);
            $this->customDomain = '';
            session()->flash('status', 'Custom domain saved.');
        } catch (InvalidCustomDomain $e) {
            $this->addError('customDomain', $e->getMessage());
        }
    }

    public function clearDomain(): void
    {
        app(ManageCustomDomain::class)->clear();
        $this->currentDomain = null;
    }

    /** @return array<string, string> live preview tokens for the swatch board */
    public function previewTokens(): array
    {
        return PaletteTokens::normalize($this->palette);
    }
}; ?>

<div style="display:flex;flex-direction:column;gap:24px">
    <header class="cbx-page-header">
        <div>
            <p class="cbx-page-eyebrow">White-label</p>
            <h1 class="cbx-page-title">Branding</h1>
            <p class="cbx-page-desc">Theme the console and hosted sign-in for this environment — palette, logo, app name, custom domain and email sender.</p>
        </div>
    </header>

    @if (session('status'))
        <div role="status" aria-live="polite" class="badge badge-success" style="align-self:flex-start">{{ session('status') }}</div>
    @endif

    {{-- Live preview: applies the (validated) tokens to a scoped surface only. --}}
    @php($preview = $this->previewTokens())
    <section class="cbx-panel">
        <div class="cbx-panel-header"><h2 class="cbx-panel-title">Preview</h2></div>
        <div class="cbx-panel-body" style="{{ collect($preview)->map(fn ($v, $k) => $k.':'.$v)->implode(';') }}">
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                @forelse ($preview as $name => $value)
                    <span class="badge" title="{{ $name }}">
                        <span style="width:14px;height:14px;border-radius:4px;background:{{ $value }};display:inline-block"></span>
                        {{ $name }}
                    </span>
                @empty
                    <span style="color:var(--muted-foreground)">No custom colours yet — the default Cbox theme is in use.</span>
                @endforelse
            </div>
            <button type="button" class="btn btn-primary" style="margin-top:16px">{{ $appName !== '' ? $appName : 'Cbox ID' }}</button>
        </div>
    </section>

    <form wire:submit="save" class="cbx-panel">
        <div class="cbx-panel-header"><h2 class="cbx-panel-title">Palette &amp; identity</h2></div>
        <div class="cbx-panel-body" style="display:flex;flex-direction:column;gap:16px">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px">
                @foreach (\Cbox\Id\Whitelabel\Support\PaletteTokens::TOKENS as $token)
                    <div>
                        <label class="label" for="tok-{{ $token }}">{{ ucfirst($token) }}</label>
                        <input id="tok-{{ $token }}" wire:model="palette.{{ $token }}" type="text"
                               class="input mono" placeholder="#0a2540 or oklch(…)"
                               @error('palette.'.$token) aria-invalid="true" @enderror>
                        @error('palette.'.$token) <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div>
                    <label class="label" for="appName">App name</label>
                    <input id="appName" wire:model="appName" type="text" class="input" placeholder="Acme ID">
                </div>
                <div>
                    <label class="label" for="emailFromName">Email sender name</label>
                    <input id="emailFromName" wire:model="emailFromName" type="text" class="input" placeholder="Acme Security">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div>
                    <label class="label" for="logo">Logo</label>
                    @if ($logoUrl)<img src="{{ $logoUrl }}" alt="Current logo" style="max-height:2rem;margin-bottom:6px">@endif
                    <input id="logo" wire:model="logo" type="file" accept="image/*" class="input">
                </div>
                <div>
                    <label class="label" for="favicon">Favicon</label>
                    @if ($faviconUrl)<img src="{{ $faviconUrl }}" alt="Current favicon" style="height:1.25rem;margin-bottom:6px">@endif
                    <input id="favicon" wire:model="favicon" type="file" accept="image/*" class="input">
                </div>
            </div>

            <div>
                <label class="label" for="emailTemplate">Welcome email (preview)</label>
                <textarea id="emailTemplate" wire:model="emailTemplate" rows="4" class="input" style="height:auto;padding:10px" placeholder="Welcome to {app}. Your account is ready."></textarea>
                <p class="cbx-panel-desc" style="margin-top:8px">Rendered with your sender name{{ $emailFromName !== '' ? ' “'.$emailFromName.'”' : '' }}.</p>
            </div>

            <div><button type="submit" class="btn btn-primary">Save branding</button></div>
        </div>
    </form>

    <section class="cbx-panel">
        <div class="cbx-panel-header"><h2 class="cbx-panel-title">Custom domain</h2></div>
        <div class="cbx-panel-body" style="display:flex;flex-direction:column;gap:12px">
            @if ($currentDomain)
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
                    <span class="cbx-pill cbx-pill--success"><span class="dot"></span>{{ $currentDomain }}</span>
                    <button type="button" wire:click="clearDomain" class="btn btn-danger btn-sm">Remove</button>
                </div>
                <p class="cbx-panel-desc">Point a CNAME for this host at the platform. Sign-in served here is themed with this brand.</p>
            @else
                <form wire:submit="saveDomain" style="display:flex;gap:8px;align-items:flex-start">
                    <div style="flex:1">
                        <input wire:model="customDomain" type="text" class="input mono" placeholder="id.acme.com"
                               @error('customDomain') aria-invalid="true" @enderror>
                        @error('customDomain') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="btn btn-primary">Add domain</button>
                </form>
                <p class="cbx-panel-desc">A fully-qualified public hostname. Private and reserved hosts are refused.</p>
            @endif
        </div>
    </section>
</div>
