<?php

declare(strict_types=1);

use Cbox\Id\SamlIdp\Contracts\ServiceProviders;
use Cbox\Id\SamlIdp\Enums\NameIdFormat;
use Cbox\Id\SamlIdp\Models\ServiceProvider;
use Cbox\Id\SamlIdp\Support\IdpDescriptor;
use Cbox\Id\SamlIdp\ValueObjects\NewServiceProvider;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Environment control plane › Login methods — the connector library. Registers the
 * downstream SAML service providers that federate to this environment's IdP.
 *
 * Service providers are environment-owned (BelongsToEnvironment), so the registry
 * only ever resolves an SP within this environment — an id from another plane never
 * matches, closing cross-tenant id tampering. Access is gated by the env-admin
 * session (route middleware), so the account member has full CRUD here; there is no
 * per-org entitlement lock at the control-plane level.
 */
new #[Layout('components.layouts.environment', ['title' => 'Login methods'])] class extends Component
{
    public bool $creating = false;

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

    public function create(ServiceProviders $providers): void
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

            return;
        }

        $providers->register(new NewServiceProvider(
            entityId: $data['entity_id'],
            acsUrl: $data['acs_url'],
            nameIdFormat: $format,
            nameIdAttribute: $data['name_id_attribute'],
            attributeMappings: $this->parseMappings(),
            certificate: $this->certificate !== '' ? $this->certificate : null,
            wantAuthnRequestsSigned: $this->want_authn_requests_signed,
        ));

        $this->reset('creating', 'entity_id', 'acs_url', 'name_id_attribute', 'want_authn_requests_signed', 'certificate');
        $this->name_id_format = 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress';
        $this->attribute_mappings = "email = email\ndisplayName = name";

        session()->flash('status', 'Login method registered.');
    }

    public function remove(string $id, ServiceProviders $providers): void
    {
        $this->ownedProvider($id, $providers)->delete();

        session()->flash('status', 'Login method removed.');
    }

    /**
     * Resolve an SP THIS environment owns, or refuse. findById is environment-scoped,
     * so an id from another plane resolves to null and is a 404 — never a cross-tenant
     * delete (deny-by-default).
     */
    private function ownedProvider(string $id, ServiceProviders $providers): ServiceProvider
    {
        $provider = $providers->findById($id);

        abort_if($provider === null, 404);

        return $provider;
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
            'providers' => app(ServiceProviders::class)->all(),
            'idpEntityId' => IdpDescriptor::entityId(),
            'idpMetadataUrl' => IdpDescriptor::metadataUrl(),
            'idpSsoUrl' => IdpDescriptor::ssoUrl(),
            'formats' => NameIdFormat::cases(),
        ];
    }
}; ?>

<div>
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Login methods</h1>
            <p class="mt-1 text-sm" style="color:var(--muted)">Register the applications that use this environment as their SAML identity provider.</p>
        </div>
        <button wire:click="$toggle('creating')" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> Add method</button>
    </div>

    {{-- The IdP coordinates the admin hands to the service provider being registered. --}}
    <div class="mt-6 rounded-xl border p-5 space-y-3" style="border-color:var(--border)">
        <p class="text-sm font-medium">Your identity provider</p>
        <p class="text-xs" style="color:var(--faint)">Give these to the service provider so it can trust assertions from this environment.</p>
        <div>
            <p class="label">IdP entity ID</p>
            <p class="mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $idpEntityId }}</p>
        </div>
        <div>
            <p class="label">Metadata URL</p>
            <p class="mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $idpMetadataUrl }}</p>
        </div>
        <div>
            <p class="label">Sign-on URL</p>
            <p class="mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $idpSsoUrl }}</p>
        </div>
    </div>

    @if ($creating)
        <form wire:submit="create" class="mt-6 rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="entity_id">SP entity ID</label>
                    <input wire:model="entity_id" id="entity_id" type="text" class="input mono" placeholder="https://saml.salesforce.com" autofocus>
                    @error('entity_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="acs_url">Assertion Consumer Service URL</label>
                    <input wire:model="acs_url" id="acs_url" type="url" class="input mono" placeholder="https://login.salesforce.com/?saml=...">
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
                    <input wire:model="name_id_attribute" id="name_id_attribute" type="text" class="input mono" placeholder="email">
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
                    <textarea wire:model="certificate" id="certificate" rows="4" class="input mono" style="font-size:0.78rem" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
                    @error('certificate') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            @endif

            <div class="flex items-center gap-2">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Register method</button>
                <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
            </div>
        </form>
    @endif

    <div class="mt-6 space-y-4">
        @forelse ($providers as $sp)
            <div class="rounded-xl border p-5" style="border-color:var(--border)">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="font-semibold truncate mono">{{ $sp->entity_id }}</p>
                            <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $sp->isActive() ? 'Active' : ucfirst($sp->status->value) }}</span>
                            @if ($sp->want_authn_requests_signed)
                                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">Signed requests</span>
                            @endif
                        </div>
                        <p class="mt-1 text-xs mono truncate" style="color:var(--faint)">{{ $sp->id }}</p>
                    </div>
                    <button wire:click="remove('{{ $sp->id }}')" wire:confirm="Remove {{ $sp->entity_id }}?" class="btn btn-ghost btn-sm shrink-0" style="color:var(--destructive)">Remove</button>
                </div>

                <div class="mt-4">
                    <p class="label">ACS URL</p>
                    <p class="mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $sp->acs_url }}</p>
                </div>
            </div>
        @empty
            <p class="rounded-xl border p-4 text-sm" style="border-color:var(--border);color:var(--muted)">No login methods yet. Add one to let an application sign users in through this environment.</p>
        @endforelse
    </div>
</div>
