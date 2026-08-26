<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\PaginationProps;
use App\Http\Requests\Console\AttributeMappings;
use App\Http\Requests\Console\SaveServiceProviderRequest;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\VerifiedEmailGate;
use Cbox\Id\SamlIdp\Contracts\ServiceProviders;
use Cbox\Id\SamlIdp\Enums\NameIdFormat;
use Cbox\Id\SamlIdp\Models\ServiceProvider;
use Cbox\Id\SamlIdp\Support\IdpDescriptor;
use Cbox\Id\SamlIdp\ValueObjects\NewServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * ENVIRONMENT PLANE › SAML APPLICATIONS — the applications that trust this environment as
 * their identity provider.
 *
 * THE OTHER DIRECTION FROM "SINGLE SIGN-ON", and the page says so, because the two read
 * identically at a glance and do opposite things: a connection there lets people sign in
 * to THIS platform with an account they already have elsewhere; a service provider here
 * lets people sign in to somebody ELSE'S application with the account they have here.
 *
 * Every read and write re-resolves the provider through the registry, which is scoped to
 * this environment — an id from another plane resolves to null and is a 404, never a
 * cross-tenant read or write.
 */
final readonly class ServiceProviderController extends ConsoleController
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $this->assertEnvironmentAdmin();

        $query = ServiceProvider::query()->orderBy('entity_id');

        $term = trim($request->string('q')->toString());

        if ($term !== '') {
            $query->where('entity_id', 'like', '%'.$term.'%');
        }

        $page = $query->paginate(self::PER_PAGE)->withQueryString();

        return $this->page('environment/sso-providers/index', 'SAML applications', [
            'providers' => array_map(static fn (ServiceProvider $provider): array => [
                'id' => $provider->id,
                'entityId' => $provider->entity_id,
                'active' => $provider->isActive(),
                'status' => $provider->status->value,
                'signedRequests' => $provider->want_authn_requests_signed,
                'href' => route('environment.sso-providers.show', $provider->id),
            ], $page->getCollection()->all()),
            'pagination' => PaginationProps::from($page),
            'search' => $term,
            // The coordinates an administrator copies into the SP being registered. Not
            // secrets — they are in the public metadata document — but they are the whole
            // content of this screen's first minute, so they are on it rather than a click
            // away in an XML file.
            'idp' => [
                'entityId' => IdpDescriptor::entityId(),
                'metadataUrl' => IdpDescriptor::metadataUrl(),
                'ssoUrl' => IdpDescriptor::ssoUrl(),
            ],
            'createHref' => route('environment.sso-providers.create'),
        ]);
    }

    public function create(): Response
    {
        $this->assertEnvironmentAdmin();

        return $this->page('environment/sso-providers/create', 'New SAML application', [
            'formats' => $this->formatProps(),
            'defaults' => [
                'nameIdFormat' => NameIdFormat::EmailAddress->value,
                'nameIdAttribute' => 'email',
                // The two claims essentially every SP asks for, pre-filled — because an
                // empty mapping table is a working configuration that emits nothing, and
                // the failure shows up as "the app says my name is blank".
                'attributeMappings' => [
                    ['key' => 'email', 'value' => 'email'],
                    ['key' => 'displayName', 'value' => 'name'],
                ],
            ],
            'indexHref' => route('environment.sso-providers'),
            'storeHref' => route('environment.sso-providers.store'),
        ]);
    }

    public function store(SaveServiceProviderRequest $request, ServiceProviders $providers): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        // An SP is a trust relationship with somebody else's system, which is exactly the
        // shape this gate holds until an address is confirmed.
        app(VerifiedEmailGate::class)->require('register a SAML application');

        $refusal = $this->certificateRefusal($request->wantAuthnRequestsSigned(), $request->certificate(), null);

        if ($refusal !== null) {
            return $refusal;
        }

        $provider = $providers->register(new NewServiceProvider(
            entityId: $request->entityId(),
            acsUrl: $request->acsUrl(),
            nameIdFormat: $request->nameIdFormat(),
            nameIdAttribute: $request->nameIdAttribute(),
            attributeMappings: $request->attributeMappings(),
            certificate: $request->certificate(),
            wantAuthnRequestsSigned: $request->wantAuthnRequestsSigned(),
        ));

        return to_route('environment.sso-providers.show', $provider->id)
            ->with('status', 'SAML application registered.');
    }

    public function show(string $provider): Response
    {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($provider);

        return $this->page('environment/sso-providers/show', $model->entity_id, [
            'provider' => [
                'id' => $model->id,
                'entityId' => $model->entity_id,
                'acsUrl' => $model->acs_url,
                'nameIdFormat' => $model->name_id_format->value,
                'nameIdAttribute' => $model->name_id_attribute,
                'attributeMappings' => AttributeMappings::toRows($model->attribute_mappings),
                'wantAuthnRequestsSigned' => $model->want_authn_requests_signed,
                // WHETHER, never WHAT. The certificate is write-only: echoing it back would
                // put it in the page's props and therefore in the browser's history entry.
                'hasCertificate' => $model->certificate !== null,
                'active' => $model->isActive(),
                'status' => $model->status->value,
            ],
            'formats' => $this->formatProps(),
            'indexHref' => route('environment.sso-providers'),
            'urls' => [
                'update' => route('environment.sso-providers.update', $model->id),
                'destroy' => route('environment.sso-providers.destroy', $model->id),
            ],
        ]);
    }

    public function update(SaveServiceProviderRequest $request, string $provider): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $model = $this->resolve($provider);

        $refusal = $this->certificateRefusal(
            $request->wantAuthnRequestsSigned(),
            $request->certificate(),
            $model->certificate,
        );

        if ($refusal !== null) {
            return $refusal;
        }

        $model->entity_id = $request->entityId();
        $model->acs_url = $request->acsUrl();
        $model->name_id_format = $request->nameIdFormat();
        $model->name_id_attribute = $request->nameIdAttribute();
        $model->attribute_mappings = $request->attributeMappings();
        $model->want_authn_requests_signed = $request->wantAuthnRequestsSigned();

        // Only overwrite the certificate when a replacement was actually provided — a blank
        // field keeps the existing one rather than wiping it, which would silently turn off
        // the verification the flag above says is happening.
        if ($request->certificate() !== null) {
            $model->certificate = $request->certificate();
        }

        $model->save();

        return back()->with('status', 'SAML application updated.');
    }

    public function destroy(string $provider): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $this->resolve($provider)->delete();

        return to_route('environment.sso-providers')->with('status', 'SAML application removed.');
    }

    private function assertEnvironmentAdmin(): void
    {
        abort_if(app(EnvironmentAdminAuth::class)->membership() === null, 403);
    }

    /**
     * A provider THIS environment owns, or refuse.
     *
     * `findById` is environment-scoped, so an id from another plane resolves to null and is
     * a 404 — never a cross-tenant read or write.
     */
    private function resolve(string $provider): ServiceProvider
    {
        $model = app(ServiceProviders::class)->findById($provider);

        abort_if($model === null, 404);

        return $model;
    }

    /**
     * A SIGNED-REQUEST SP IS USELESS WITHOUT A CERTIFICATE TO VERIFY AGAINST.
     *
     * Refused rather than saved, because the half-configured combination does not fail
     * loudly: the flag says requests are verified and nothing verifies them, which is worse
     * than never having turned it on. `$existing` is what is already on file — a blank
     * field on the edit form means "keep it", so only the case where there is nothing at
     * all is a refusal.
     */
    private function certificateRefusal(bool $signed, ?string $supplied, ?string $existing): ?RedirectResponse
    {
        if (! $signed || $supplied !== null || $existing !== null) {
            return null;
        }

        return back()
            ->withInput()
            ->withErrors(['certificate' => 'A signing certificate is required for signed AuthnRequests.']);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function formatProps(): array
    {
        return array_map(static fn (NameIdFormat $format): array => [
            'value' => $format->value,
            // The case name, not the URN: "EmailAddress" is what an administrator is
            // choosing between, and the URN is on screen nowhere they have to read it.
            'label' => $format->name,
        ], NameIdFormat::cases());
    }
}
