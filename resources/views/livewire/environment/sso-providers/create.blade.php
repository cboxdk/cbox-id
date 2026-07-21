<?php

declare(strict_types=1);

use Cbox\Id\SamlIdp\Contracts\ServiceProviders;
use Cbox\Id\SamlIdp\Enums\NameIdFormat;
use Cbox\Id\SamlIdp\ValueObjects\NewServiceProvider;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Environment control plane › Login methods › New. A dedicated, deep-linkable create
 * page that registers a downstream SAML service provider against this environment's
 * IdP. On success we route straight to the new method's detail page.
 *
 * Registration is environment-scoped through the {@see ServiceProviders} registry, so
 * the SP is owned by this environment and invisible to every other plane.
 */
new #[Layout('components.layouts.environment', ['title' => 'New login method'])] class extends Component
{
    #[Validate('required|string|max:500')]
    public string $entity_id = '';

    #[Validate('required|url|max:1000')]
    public string $acs_url = '';

    #[Validate('required|string')]
    public string $name_id_format = 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress';

    #[Validate('required|string|max:120')]
    public string $name_id_attribute = 'email';

    /** One `samlAttribute = subjectField` per line, projected into the AttributeStatement. */
    public string $attribute_mappings = "email = email\ndisplayName = name";

    public bool $want_authn_requests_signed = false;

    /** The SP's signing certificate (PEM), required to verify signed AuthnRequests. */
    public string $certificate = '';

    public function create(ServiceProviders $providers): mixed
    {
        $data = $this->validate();

        // A signed-request SP is useless without a cert to verify against — refuse the
        // half-configured combination rather than silently never verifying.
        if ($this->want_authn_requests_signed) {
            $this->validate(['certificate' => 'required|string']);
        }

        $format = NameIdFormat::tryFrom($data['name_id_format']);

        if ($format === null) {
            $this->addError('name_id_format', 'Choose a supported NameID format.');

            return null;
        }

        $model = $providers->register(new NewServiceProvider(
            entityId: $data['entity_id'],
            acsUrl: $data['acs_url'],
            nameIdFormat: $format,
            nameIdAttribute: $data['name_id_attribute'],
            attributeMappings: $this->parseMappings(),
            certificate: $this->certificate !== '' ? $this->certificate : null,
            wantAuthnRequestsSigned: $this->want_authn_requests_signed,
        ));

        $this->dispatch('toast', message: 'Login method registered.');

        return $this->redirectRoute('environment.sso-providers.show', ['provider' => $model->id], navigate: true);
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
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'formats' => NameIdFormat::cases(),
        ];
    }
}; ?>

<div>
    <a href="{{ route('environment.sso-providers') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Login methods</a>
    <h1 class="mt-2 font-semibold tracking-tight" style="font-size:1.5rem">New login method</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">Register an application that uses this environment as its SAML identity provider.</p>

    <form wire:submit="create" class="mt-6 rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="entity_id">SP entity ID</label>
                <input wire:model="entity_id" id="entity_id" type="text" class="input mono" placeholder="https://saml.salesforce.com" autofocus>
                @error('entity_id') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="acs_url">Assertion Consumer Service URL</label>
                <input wire:model="acs_url" id="acs_url" type="url" class="input mono" placeholder="https://login.salesforce.com/?saml=...">
                @error('acs_url') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="name_id_format">NameID format</label>
                <select wire:model="name_id_format" id="name_id_format" class="select">
                    @foreach ($formats as $format)
                        <option value="{{ $format->value }}">{{ $format->name }}</option>
                    @endforeach
                </select>
                @error('name_id_format') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="name_id_attribute">NameID attribute</label>
                <input wire:model="name_id_attribute" id="name_id_attribute" type="text" class="input mono" placeholder="email">
                @error('name_id_attribute') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label class="label" for="attribute_mappings">Attribute mappings</label>
            <textarea wire:model="attribute_mappings" id="attribute_mappings" rows="3" class="input mono" style="font-size:0.78rem" placeholder="displayName = name"></textarea>
            <p class="mt-1 text-xs" style="color:var(--faint)">One <code class="mono">samlAttribute = subjectField</code> per line.</p>
            @error('attribute_mappings') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input wire:model.live="want_authn_requests_signed" type="checkbox" class="rounded"> Require signed AuthnRequests
        </label>

        @if ($want_authn_requests_signed)
            <div>
                <label class="label" for="certificate">SP signing certificate</label>
                <textarea wire:model="certificate" id="certificate" rows="4" class="input mono" style="font-size:0.78rem" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
                @error('certificate') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
        @endif

        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">Register method</button>
            <a href="{{ route('environment.sso-providers') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
