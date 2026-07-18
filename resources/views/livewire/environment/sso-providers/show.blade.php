<?php

declare(strict_types=1);

use Cbox\Id\SamlIdp\Contracts\ServiceProviders;
use Cbox\Id\SamlIdp\Enums\NameIdFormat;
use Cbox\Id\SamlIdp\Models\ServiceProvider;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Login methods › detail. The full, deep-linkable
 * lifecycle for one registered SAML service provider: entity/ACS coordinates, NameID
 * shape, attribute mappings, signed-request policy and removal.
 *
 * Every read/write re-resolves the SP within THIS environment via the
 * {@see ServiceProviders} registry (findById is BelongsToEnvironment-scoped) and 404s
 * on a foreign id — an id from another plane never matches (deny-by-default). The
 * registry exposes no update service, so config edits persist directly on the
 * env-scoped model. The stored signing certificate is never echoed back: the admin
 * only sees whether one is on file and may replace it.
 */
new #[Layout('components.layouts.environment')] class extends Component
{
    public string $providerId = '';

    public string $entity_id = '';

    public string $acs_url = '';

    public string $name_id_format = '';

    public string $name_id_attribute = '';

    /** One `samlAttribute = subjectField` per line, projected into the AttributeStatement. */
    public string $attribute_mappings = '';

    public bool $want_authn_requests_signed = false;

    /** A replacement signing certificate (PEM); blank keeps the one already on file. */
    public string $certificate = '';

    public function mount(string $provider, ServiceProviders $providers): void
    {
        $model = $providers->findById($provider);
        abort_if($model === null, 404);

        $this->providerId = $model->id;
        $this->hydrateForm($model);
    }

    /**
     * Resolve an SP THIS environment owns, or refuse. findById is environment-scoped,
     * so an id from another plane resolves to null and is a 404 — never a cross-tenant
     * read or write (deny-by-default).
     */
    private function ownedProvider(): ServiceProvider
    {
        $provider = app(ServiceProviders::class)->findById($this->providerId);

        abort_if($provider === null, 404);

        return $provider;
    }

    private function hydrateForm(ServiceProvider $model): void
    {
        $this->entity_id = $model->entity_id;
        $this->acs_url = $model->acs_url;
        $this->name_id_format = $model->name_id_format->value;
        $this->name_id_attribute = $model->name_id_attribute;
        $this->want_authn_requests_signed = $model->want_authn_requests_signed;
        $this->attribute_mappings = $this->formatMappings($model->attribute_mappings);
    }

    public function save(): void
    {
        $provider = $this->ownedProvider();

        $data = $this->validate([
            'entity_id' => ['required', 'string', 'max:500'],
            'acs_url' => ['required', 'url', 'max:1000'],
            'name_id_format' => ['required', 'string'],
            'name_id_attribute' => ['required', 'string', 'max:120'],
        ]);

        $format = NameIdFormat::tryFrom($data['name_id_format']);

        if ($format === null) {
            $this->addError('name_id_format', 'Choose a supported NameID format.');

            return;
        }

        // A signed-request SP is useless without a cert to verify against. A cert may
        // already be on file — only demand one when none exists and none is supplied.
        if ($this->want_authn_requests_signed && $this->certificate === '' && $provider->certificate === null) {
            $this->addError('certificate', 'A signing certificate is required for signed AuthnRequests.');

            return;
        }

        $provider->entity_id = $data['entity_id'];
        $provider->acs_url = $data['acs_url'];
        $provider->name_id_format = $format;
        $provider->name_id_attribute = $data['name_id_attribute'];
        $provider->attribute_mappings = $this->parseMappings();
        $provider->want_authn_requests_signed = $this->want_authn_requests_signed;

        // Only overwrite the certificate when a replacement was actually provided —
        // an empty field keeps the existing one rather than wiping it.
        if ($this->certificate !== '') {
            $provider->certificate = $this->certificate;
        }

        $provider->save();

        // The replacement cert has been persisted; never keep it in component state.
        $this->certificate = '';

        session()->flash('status', 'Login method updated.');
    }

    public function remove(): mixed
    {
        $this->ownedProvider()->delete();

        session()->flash('status', 'Login method removed.');

        return $this->redirectRoute('environment.sso-providers', navigate: true);
    }

    /**
     * Parse the `samlAttribute = subjectField` textarea into the mapping array,
     * dropping blank or malformed lines.
     *
     * @return array<string, string>
     */
    private function parseMappings(): array
    {
        $mappings = [];

        foreach (preg_split('/\r\n|\r|\n/', $this->attribute_mappings) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$attribute, $field] = explode('=', $line, 2);
            $attribute = trim($attribute);
            $field = trim($field);

            if ($attribute !== '' && $field !== '') {
                $mappings[$attribute] = $field;
            }
        }

        return $mappings;
    }

    /**
     * Render the stored mapping array back into the `samlAttribute = subjectField`
     * textarea form.
     *
     * @param  array<string, string>  $mappings
     */
    private function formatMappings(array $mappings): string
    {
        $lines = [];
        foreach ($mappings as $attribute => $field) {
            $lines[] = $attribute.' = '.$field;
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $provider = $this->ownedProvider();

        return [
            'provider' => $provider,
            'hasCertificate' => $provider->certificate !== null,
            'formats' => NameIdFormat::cases(),
        ];
    }
}; ?>

<div class="space-y-6">
    <div>
        <a href="{{ route('environment.sso-providers') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Login methods</a>
        <div class="mt-2 flex items-center gap-3 flex-wrap">
            <h1 class="font-semibold tracking-tight mono" style="font-size:1.5rem">{{ $provider->entity_id }}</h1>
            @if ($provider->want_authn_requests_signed)
                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">Signed requests</span>
            @endif
            <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $provider->isActive() ? 'Active' : ucfirst($provider->status->value) }}</span>
        </div>
        <p class="mt-1 text-sm mono" style="color:var(--faint)">{{ $provider->id }}</p>
    </div>

    {{-- Configuration --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Configuration</p>
        <form wire:submit="save" class="mt-4 space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="entity_id">SP entity ID</label>
                    <input wire:model="entity_id" id="entity_id" type="text" class="input mono">
                    @error('entity_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="acs_url">Assertion Consumer Service URL</label>
                    <input wire:model="acs_url" id="acs_url" type="url" class="input mono">
                    @error('acs_url') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="name_id_format">NameID format</label>
                    <select wire:model="name_id_format" id="name_id_format" class="select">
                        @foreach ($formats as $format)
                            <option value="{{ $format->value }}">{{ $format->name }}</option>
                        @endforeach
                    </select>
                    @error('name_id_format') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="name_id_attribute">NameID attribute</label>
                    <input wire:model="name_id_attribute" id="name_id_attribute" type="text" class="input mono">
                    @error('name_id_attribute') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="label" for="attribute_mappings">Attribute mappings</label>
                <textarea wire:model="attribute_mappings" id="attribute_mappings" rows="3" class="input mono" style="font-size:0.78rem" placeholder="displayName = name"></textarea>
                <p class="mt-1 text-xs" style="color:var(--faint)">One <code class="mono">samlAttribute = subjectField</code> per line.</p>
                @error('attribute_mappings') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input wire:model.live="want_authn_requests_signed" type="checkbox" class="rounded"> Require signed AuthnRequests
            </label>

            @if ($want_authn_requests_signed)
                <div>
                    <label class="label" for="certificate">SP signing certificate</label>
                    @if ($hasCertificate)
                        <p class="mb-1 text-xs" style="color:var(--faint)">A certificate is on file. Paste a new one to replace it, or leave blank to keep it.</p>
                    @endif
                    <textarea wire:model="certificate" id="certificate" rows="4" class="input mono" style="font-size:0.78rem" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
                    @error('certificate') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            @endif

            <button type="submit" class="btn btn-primary">Save changes</button>
        </form>
    </div>

    {{-- Danger zone --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Remove</p>
        <p class="mt-1 text-sm" style="color:var(--muted)">Deleting this method stops the application from signing users in through this environment.</p>
        <div class="mt-4">
            <button type="button" class="btn btn-ghost btn-sm" style="color:var(--destructive)" wire:click="remove" wire:confirm="Remove {{ $provider->entity_id }}? The application can no longer sign users in through this environment.">Delete login method</button>
        </div>
    </div>
</div>
