<?php

use App\Platform\CurrentUser;
use Cbox\Id\Whitelabel\Assets\BrandAssetStore;
use Cbox\Id\Whitelabel\Contracts\BrandProfiles;
use Cbox\Id\Whitelabel\Models\BrandProfile;
use Cbox\Id\Whitelabel\Support\PaletteTokens;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app', ['title' => 'Branding'])] class extends Component
{
    /**
     * Route middleware does not gate this page: the module routes carry `platform.auth`
     * (a session exists) and `console.feature` (the flag is on), and neither is a role
     * check. The nav hides the area from a plain member, which is styling, not
     * authorization — the URL is typeable. Guarded in boot() rather than mount() so it
     * re-runs on every Livewire message, not just the first render.
     */
    public function boot(): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);
    }
    use WithFileUploads;

    /** @var array<string, string> */
    public array $palette = [];

    public string $appName = '';

    public string $emailFromName = '';

    public string $emailTemplate = '';

    /**
     * Server-derived, so locked: a client that can set these can name another
     * environment's asset and have `forget()` delete it on the next upload, or point the
     * environment's logo at a host of its choosing — a beacon on every branded page.
     */
    #[Locked]
    public ?string $logoUrl = null;

    #[Locked]
    public ?string $faviconUrl = null;

    public ?UploadedFile $logo = null;

    public ?UploadedFile $favicon = null;

    public function mount(): void
    {
        $this->palette = array_fill_keys(PaletteTokens::TOKENS, '');

        $profile = app(BrandProfiles::class)->forOrganization($this->organizationId());

        // No profile yet: every field stays at its blank default and the form renders
        // as "not branded", which is a different state from branded-then-cleared.
        if ($profile === null) {
            return;
        }

        foreach (PaletteTokens::TOKENS as $token) {
            $this->palette[$token] = $profile->palette->get($token) ?? '';
        }

        $this->appName = $profile->app_name ?? '';
        $this->emailFromName = $profile->email_from_name ?? '';
        $this->emailTemplate = $profile->email_templates->get('welcome') ?? '';
        $this->logoUrl = $profile->logo_url;
        $this->faviconUrl = $profile->favicon_url;
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

        // The uploads carry NO server-side type gate beyond `accept="image/*"` on the
        // input, which is a hint to the file picker and nothing more. The store keeps the
        // content-guessed extension and writes to the public disk, so an SVG containing a
        // script tag is then served from the application's OWN origin — a static path, so
        // neither the CSP nor nosniff applies to it, and every admin's session cookie
        // lives on that origin. SVG is excluded deliberately: it is the only raster-
        // adjacent format that is also a script host.
        $this->validate([
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'favicon' => ['nullable', 'image', 'mimes:png,ico,webp', 'max:256'],
        ], [
            'logo.mimes' => 'Use a PNG, JPEG or WebP. SVG is not accepted — it can carry a script.',
            'favicon.mimes' => 'Use a PNG, ICO or WebP. SVG is not accepted — it can carry a script.',
        ]);

        $profiles = app(BrandProfiles::class);
        $assets = app(BrandAssetStore::class);

        // THIS organization's profile.
        //
        // It used to read and write `forEnvironment()` — the `organization_id IS NULL`
        // row, which is the fallback every organization in the environment inherits when
        // it has none of its own. Behind an ORG-admin check. So an admin of one tenant
        // re-branded the console and the hosted sign-in page for every other tenant in
        // the environment, none of whom share any trust relationship with them.
        //
        // The resolver already prefers an organization's own profile over the
        // environment default, so writing at this altitude is both the safe choice and
        // the one the data model was built for. The environment-wide default belongs to
        // an environment administrator.
        $organizationId = $this->organizationId();

        $profile = $profiles->forOrganization($organizationId)
            ?? new BrandProfile(['organization_id' => $organizationId]);

        if ($this->logo !== null) {
            $assets->forget($profile->logo_url);
            $this->logoUrl = $assets->put('logo', $this->logo);
        }

        if ($this->favicon !== null) {
            $assets->forget($profile->favicon_url);
            $this->faviconUrl = $assets->put('favicon', $this->favicon);
        }

        $profile->fill([
            'organization_id' => $organizationId,
            'palette' => $clean,
            'app_name' => $this->appName !== '' ? $this->appName : null,
            'email_from_name' => $this->emailFromName !== '' ? $this->emailFromName : null,
            'email_templates' => $profile->email_templates->with('welcome', $this->emailTemplate),
            'logo_url' => $this->logoUrl,
            'favicon_url' => $this->faviconUrl,
        ]);

        $profiles->save($profile);

        $this->logo = null;
        $this->favicon = null;
        session()->flash('status', 'Branding saved.');
    }

    /**
     * The organization whose branding this page edits.
     *
     * Never a value from the request: the page is reached by an org admin, and the only
     * organization they may brand is the one their session is currently in.
     */
    private function organizationId(): string
    {
        $organizationId = app(CurrentUser::class)->organization()?->id;

        abort_if($organizationId === null, 403);

        return $organizationId;
    }

    /** @return array<string, string> live preview tokens for the swatch board */
    public function previewTokens(): array
    {
        return PaletteTokens::normalize($this->palette);
    }
}; ?>

<div style="display:flex;flex-direction:column;gap:24px">
    {{-- The eyebrow is resolved from the nav registry by <x-page-header>, so it says
         "Settings" — the area this page actually lives in. Hand-written, it said
         "White-label", which is the module's name and not a place in the console. --}}
    <x-page-header title="Branding"
                   subtitle="Theme the console and hosted sign-in for this whole environment — palette, logo, app name, custom domain and email sender. An organization can override the sign-in theme under Appearance." />

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

    {{-- The custom-domain controls that used to sit here are gone, not moved: the
         account plane already owns them at Workspace › Environment domains, where they
         are scoped to environments the member can actually reach and verified by a DNS
         TXT record. This copy was neither — an org admin could take down sign-in at the
         environment's vanity host for every other tenant in it, or point it somewhere of
         their choosing, with nothing but an organization-level role. --}}
</div>
