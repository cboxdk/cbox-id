<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\Entitlements;
use Cbox\Id\SamlIdp\Contracts\ServiceProviders;
use Cbox\Id\SamlIdp\Enums\NameIdFormat;
use Cbox\Id\SamlIdp\Models\ServiceProvider;
use Cbox\Id\SamlIdp\Support\IdpDescriptor;
use Cbox\Id\SamlIdp\ValueObjects\NewServiceProvider;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Register the downstream SAML service providers that may federate to this
 * platform's IdP. Mirrors the SSO connections screen: admin-only, entitlement-gated
 * under `cbox-id-sso`, deny-by-default on every mutating action.
 *
 * Service providers are environment-owned (the framework's hard scope), so the
 * registry only ever resolves an SP within the environment it was registered in —
 * an id from another plane simply never matches, closing cross-tenant id tampering.
 */
new #[Layout('components.layouts.app', ['title' => 'SSO providers'])] class extends Component
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
        $this->guardEntitled();
        $this->authorizeAdmin();

        $data = $this->validate();

        // A signed-request SP is useless without a cert to verify against — refuse
        // the half-configured combination rather than silently never verifying.
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

        session()->flash('status', 'Service provider registered.');
    }

    public function remove(string $id, ServiceProviders $providers): void
    {
        $this->guardEntitled();
        $this->authorizeAdmin();

        $this->ownedProvider($id, $providers)->delete();

        session()->flash('status', 'Service provider removed.');
    }

    /**
     * Resolve an SP the CURRENT environment owns, or refuse. findById is
     * environment-scoped, so an id from another plane resolves to null and is a
     * 403 — never a cross-tenant delete (deny-by-default).
     */
    private function ownedProvider(string $id, ServiceProviders $providers): ServiceProvider
    {
        $provider = $providers->findById($id);

        abort_if($provider === null, 403);

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

    public function with(): array
    {
        return [
            'me' => app(CurrentUser::class),
            'entitled' => app(Entitlements::class)->entitled($this->orgId(), 'sso'),
            'providers' => app(ServiceProviders::class)->all(),
            'idpEntityId' => IdpDescriptor::entityId(),
            'idpMetadataUrl' => IdpDescriptor::metadataUrl(),
            'idpSsoUrl' => IdpDescriptor::ssoUrl(),
            'formats' => NameIdFormat::cases(),
        ];
    }

    private function orgId(): string
    {
        return app(CurrentUser::class)->organizationId() ?? '';
    }

    public function mount(): void
    {
        // Read gate: SP registrations are org-wide config (ACS targets, attribute
        // release) — admins only, same as the connections screen.
        $this->authorizeAdmin();
    }

    private function authorizeAdmin(): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);
    }

    /**
     * Deny-by-default entitlement gate for every mutating action. Runs BEFORE the
     * admin check so a direct Livewire call from a non-entitled org is refused even
     * though the (upsell) screen itself is reachable.
     */
    private function guardEntitled(): void
    {
        abort_unless(app(Entitlements::class)->entitled($this->orgId(), 'sso'), 403);
    }
}; ?>

<div>
    <div class="cbx-page-header">
        <div>
            <p class="cbx-page-eyebrow">Identity provider</p>
            <h1 class="cbx-page-title">SSO providers</h1>
            <p class="cbx-page-desc">Register the applications that use this platform as their SAML identity provider.</p>
        </div>
        @if ($me->isAdmin() && $entitled)
            <div class="flex items-center gap-2">
                <button wire:click="$toggle('creating')" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> Add provider</button>
            </div>
        @endif
    </div>

    <div class="mt-8 space-y-6">
    @if (session('status'))
        <div class="card p-3 text-sm flex items-center gap-2" style="border-color:color-mix(in oklch, var(--success) 30%, transparent);background:var(--success-soft);color:var(--success)">
            <x-icon name="check" class="w-4 h-4" /> {{ session('status') }}
        </div>
    @endif

    @if (! $entitled)
        <div class="card">
            <div class="cbx-empty">
                <div class="cbx-empty-icon"><x-icon name="connections" class="w-5 h-5" /></div>
                <h3>SAML identity provider is an Enterprise feature</h3>
                <p>
                    Acting as the SAML IdP that your applications federate to is available on the Enterprise plan.
                    Contact your account team to enable it for this organization.
                </p>
            </div>
        </div>
    @else

    {{-- Our IdP coordinates — the admin imports these into the SP they are registering. --}}
    <div class="cbx-panel">
        <div class="cbx-panel-header">
            <div>
                <div class="cbx-panel-title flex items-center gap-2"><x-icon name="connections" class="w-4 h-4" /> Your identity provider</div>
                <p class="cbx-panel-desc">Give these to the service provider so it can trust assertions from this platform.</p>
            </div>
        </div>
        <div class="cbx-panel-body space-y-3">
            <div>
                <p class="label">IdP entity ID</p>
                <p class="mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--secondary);border:1px solid var(--border)">{{ $idpEntityId }}</p>
            </div>
            <div>
                <p class="label">Metadata URL</p>
                <p class="mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--secondary);border:1px solid var(--border)">{{ $idpMetadataUrl }}</p>
            </div>
            <div>
                <p class="label">Sign-on URL</p>
                <p class="mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--secondary);border:1px solid var(--border)">{{ $idpSsoUrl }}</p>
            </div>
        </div>
    </div>

    @if ($creating && $me->isAdmin())
        <form wire:submit="create" class="card p-5 space-y-4">
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
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Register provider</button>
                <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
            </div>
        </form>
    @endif

    <div class="space-y-4">
        @forelse ($providers as $sp)
            <div class="card p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="font-semibold truncate mono">{{ $sp->entity_id }}</p>
                            @if ($sp->isActive())
                                <span class="cbx-pill cbx-pill--success"><span class="dot"></span> Active</span>
                            @else
                                <span class="cbx-pill"><span class="dot"></span> {{ ucfirst($sp->status->value) }}</span>
                            @endif
                            @if ($sp->want_authn_requests_signed)
                                <span class="cbx-pill cbx-pill--info"><span class="dot"></span> Signed requests</span>
                            @endif
                        </div>
                        <p class="mt-1 text-xs mono truncate" style="color:var(--muted-foreground)">{{ $sp->id }}</p>
                    </div>
                    @if ($me->isAdmin())
                        <button wire:click="remove('{{ $sp->id }}')" wire:confirm="Remove {{ $sp->entity_id }}?" class="btn btn-ghost btn-sm">Remove</button>
                    @endif
                </div>

                <div class="mt-4">
                    <p class="label">ACS URL</p>
                    <p class="mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--secondary);border:1px solid var(--border)">{{ $sp->acs_url }}</p>
                </div>
            </div>
        @empty
            <div class="card">
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="connections" class="w-5 h-5" /></div>
                    <h3>No service providers yet</h3>
                    <p>Register an application to let it sign users in through this platform.</p>
                </div>
            </div>
        @endforelse
    </div>
    @endif
    </div>
</div>
