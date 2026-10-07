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
use Cbox\Id\Organization\Models\Organization;
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
 * WHOSE AN APPLICATION IS. An application can belong to one organization — only that
 * organization's active members are then signed in to it — or to the whole environment,
 * where anybody with an account here can single-sign-on into it. The second is how every
 * application behaved before 1.22, so the list flags it rather than letting a customer's
 * own app stay open to every other tenant's people unnoticed.
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
        'organization_id' => 'organizationId',
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

        // Named for this page's rows only, in one query: the list says WHOSE each app is.
        $owners = $this->organizationNames(array_values(array_filter(
            $page->getCollection()->map(static fn (ServiceProvider $provider): ?string => $provider->organization_id)->all(),
            static fn (?string $id): bool => $id !== null && $id !== '',
        )));

        return $this->page('environment/sso-providers/index', 'SAML applications', [
            'help' => HelpProps::for(HelpTopic::SamlApplications),
            'providers' => array_map(static fn (ServiceProvider $provider): array => [
                'id' => $provider->id,
                'entityId' => $provider->entity_id,
                'active' => $provider->isActive(),
                'status' => $provider->status->value,
                'signedRequests' => $provider->want_authn_requests_signed,
                // Null is ENVIRONMENT-WIDE — every person here can sign in to it — and the
                // list flags it, because that is rarely what one customer's app should be.
                'organization' => $provider->isOrganizationOwned()
                    ? ($owners[(string) $provider->organization_id] ?? (string) $provider->organization_id)
                    : null,
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
            'organizations' => $this->organizationOptions(),
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
                'organizationId' => $model->isOrganizationOwned() ? (string) $model->organization_id : '',
            ],
            'formats' => $this->formatProps(),
            'organizations' => $this->organizationOptions(),
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
            // Always sent, so clearing the choice on the edit form is a change rather than
            // "left out": null makes the application environment-wide again.
            'organization_id' => $request->organizationId(),
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
     * Names for the given organizations of this environment (the model is environment-scoped,
     * so an id from anywhere else is simply absent).
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function organizationNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $names = [];

        foreach (Organization::query()->whereKey(array_values(array_unique($ids)))->get(['id', 'name']) as $organization) {
            $names[(string) $organization->id] = (string) $organization->name;
        }

        return $names;
    }

    /**
     * Who an application can be for: every organization in this environment, by name, with
     * its slug searchable — the same "for which organization?" choice the other pages ask.
     * The empty value is the environment-wide answer and leads the list, so it is a choice
     * somebody makes rather than what they get by not looking.
     *
     * @return list<array{value: string, label: string, keywords: list<string>}>
     */
    private function organizationOptions(): array
    {
        $options = [['value' => '', 'label' => 'Every organization (environment-wide)', 'keywords' => []]];

        foreach (Organization::query()->orderBy('name')->get(['id', 'name', 'slug']) as $organization) {
            $options[] = [
                'value' => (string) $organization->id,
                'label' => (string) $organization->name,
                'keywords' => [(string) $organization->slug],
            ];
        }

        return $options;
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
