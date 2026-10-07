<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\SamlApps\CreateSamlApp;
use App\Actions\SamlApps\DeleteSamlApp;
use App\Actions\SamlApps\SamlAppFields;
use App\Actions\SamlApps\UpdateSamlApp;
use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Http\Requests\Console\AttributeMappings;
use App\Http\Requests\Console\SaveServiceProviderRequest;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\Help\HelpTopic;
use App\Platform\VerifiedEmailGate;
use Cbox\Id\SamlIdp\Contracts\ServiceProviders;
use Cbox\Id\SamlIdp\Enums\NameIdFormat;
use Cbox\Id\SamlIdp\Models\ServiceProvider;
use Cbox\Id\SamlIdp\Support\IdpDescriptor;
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
 *
 * Every write is an ACTION (`App\Actions\SamlApps\*`), the same class the management API's
 * `/v1/saml-apps` runs — the certificate rule, the unique entity id and the audit entry are
 * the action's. This controller maps the form onto it.
 */
final readonly class ServiceProviderController extends ConsoleController
{
    private const PER_PAGE = 25;

    /** The action's input names, as this page's form fields. */
    private const FIELDS = [
        'entity_id' => 'entityId',
        'acs_url' => 'acsUrl',
        'name_id_format' => 'nameIdFormat',
        'name_id_attribute' => 'nameIdAttribute',
        'attribute_mappings' => 'attributeMappings',
        'want_authn_requests_signed' => 'wantAuthnRequestsSigned',
        'certificate' => 'certificate',
    ];

    public function index(Request $request): Response
    {
        $this->assertEnvironmentAdmin();

        $query = ServiceProvider::query()->orderBy('entity_id');

        $term = trim($request->string('q')->toString());

        if ($term !== '') {
            $query->where('entity_id', 'like', '%'.$term.'%');
        }

        $page = $query->paginate(self::PER_PAGE)->withQueryString();

        return $this->page('environment/sso-providers/index', 'SAML apps', [
            'help' => HelpProps::for(HelpTopic::SamlApplications),
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

        return $this->page('environment/sso-providers/create', 'New SAML app', [
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

    public function store(SaveServiceProviderRequest $request): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        // An SP is a trust relationship with somebody else's system, which is exactly the
        // shape this gate holds until an address is confirmed.
        app(VerifiedEmailGate::class)->require('register a SAML application');

        $result = $this->act(CreateSamlApp::class, [
            'entity_id' => $request->entityId(),
            ...$this->input($request),
        ], self::FIELDS, 'entityId');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var ServiceProvider $provider */
        $provider = $result->value;

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

        // A blank certificate field keeps the one on file — the action only ever REPLACES
        // it — rather than wiping it and silently turning off the verification the flag
        // says is happening.
        $result = $this->act(UpdateSamlApp::class, [
            'id' => $model->id,
            'entity_id' => $request->entityId(),
            ...$this->input($request),
        ], self::FIELDS, 'entityId');

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'SAML application updated.');
    }

    public function destroy(string $provider): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $result = $this->act(DeleteSamlApp::class, ['id' => $this->resolve($provider)->id]);

        return $result instanceof RedirectResponse
            ? $result
            : to_route('environment.sso-providers')->with('status', 'SAML application removed.');
    }

    /**
     * The form, as the action's input. Everything but the entity id, which the two writes
     * treat differently.
     *
     * @return array<string, mixed>
     */
    private function input(SaveServiceProviderRequest $request): array
    {
        return [
            'acs_url' => $request->acsUrl(),
            'name_id_format' => $request->nameIdFormat()->value,
            'name_id_attribute' => $request->nameIdAttribute(),
            'attribute_mappings' => SamlAppFields::rows($request->attributeMappings()),
            'want_authn_requests_signed' => $request->wantAuthnRequestsSigned(),
            'certificate' => $request->certificate(),
        ];
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
