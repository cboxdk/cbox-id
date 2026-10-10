<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\SignIn\EnableSocialProvider;
use App\Actions\SignIn\RemoveSocialProvider;
use App\Actions\SignIn\SetSocialProviderInheritance;
use App\Actions\SignIn\SocialProviderFields;
use App\Actions\SignIn\TurnOffSocialProvider;
use App\Actions\SignIn\TurnOnSocialProvider;
use App\Actions\SignIn\UpdateSocialProvider;
use App\Http\Props\Shared\HelpProps;
use App\Http\Requests\Console\EnableSocialProviderRequest;
use App\Http\Requests\Console\UpdateSocialProviderRequest;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\Vocabulary;
use App\Platform\Help\HelpTopic;
use App\Platform\VerifiedEmailGate;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\SignInProviders;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Enums\ProviderCapability;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\ProviderCatalog;
use Cbox\Id\Federation\ValueObjects\ProviderTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Response;

/**
 * SOCIAL SIGN-IN — pick a provider from a list instead of describing one from memory.
 *
 * TWO OWNERS, ONE PAGE. A provider belongs to the ENVIRONMENT — offered on every sign-in page
 * in it, which is what "turn on Google for my app" means and therefore what the setup form
 * offers first — or to one ORGANIZATION, which then replaces the environment's for that
 * provider on its own page. An organization can also turn one of the environment's off for
 * its page without bringing its own. The precedence is the framework's
 * ({@see SignInProviders}); this page draws it in two shapes:
 *
 *  - the ENVIRONMENT view (the environment console, unfiltered): the environment's
 *    providers, then every organization's own, then which organizations turned one off;
 *  - an ORGANIZATION view (one organization's console, or the list filtered to one): what
 *    that organization's sign-in page actually shows, each button saying whether it is
 *    inherited or the organization's own.
 *
 * Everything an administrator used to have to know — Google's issuer, that Entra's names
 * the directory, that GitHub is not an OpenID Provider at all, which scopes carry an
 * address — is catalogue data. What is left is the part that genuinely is theirs: the
 * client id and secret from their own account with that provider.
 *
 * THE REAL REDIRECT URI, BEFORE SAVING. The URI contains the provider's id, which used to
 * exist only after saving, so the form showed a `{connection}` placeholder and asked people
 * to come back and fix it — the single most common way any of these failed. The form now
 * reserves the id when it is drawn (kept in the session, so a reload while somebody is in
 * the provider's console in another tab shows the same URI), and the provider is created
 * under it ({@see SignInProviders::create()}).
 *
 * Every write is an ACTION — `signin.social.set`, `.update`, `.enable`, `.disable`,
 * `.delete`, `.inherit` — the same classes the management API runs; this controller maps
 * the forms onto them.
 */
final readonly class SocialProviderController extends ConsoleController
{
    /** The session key the setup form's reserved id lives under, per provider. */
    private const RESERVED = 'social-setup.reserved.';

    public function index(Request $request, SignInProviders $providers): Response
    {
        $this->scope->assertMayAdminister();

        $filter = $this->organizationFilter();
        $organizationId = $filter->id;
        $environmentView = $organizationId === null && ! $filter->unknown;
        // A single-tenant install's organization console: its administrators are the
        // environment's, so the environment's providers are managed here as well.
        $managesEnvironment = $this->managesEnvironmentHere();

        /** @var list<Connection> $environment */
        $environment = $filter->unknown ? [] : $providers->environmentProviders();
        $environmentKeys = array_map(static fn (Connection $connection): string => (string) $connection->provider, $environment);

        /** @var list<Connection> $own */
        $own = match (true) {
            $filter->unknown => [],
            $organizationId !== null => array_values(Connection::query()->where('organization_id', $organizationId)->whereNotNull('provider')->orderBy('provider')->get()->all()),
            default => array_values(Connection::query()->whereNotNull('organization_id')->whereNotNull('provider')->orderBy('provider')->get()->all()),
        };

        $owners = $environmentView
            ? $this->scope->organizationNames([
                ...array_map(static fn (Connection $connection): ?string => $connection->organization_id, $own),
                ...array_keys($providers->optOuts()),
            ])
            : [];

        $template = SocialProviderFields::loginTemplate($request->string('provider')->toString());
        $editing = $this->editing($request, $organizationId);

        // What can still be added for the owner the page is about: the environment's on the
        // environment view (an organization's own can be added from the same form), the
        // organization's on an organization view.
        $taken = $environmentView
            ? $environmentKeys
            : array_map(static fn (Connection $connection): string => (string) $connection->provider, $own);

        return $this->page('console/social-providers', Vocabulary::SOCIAL_LOGIN, [
            'view' => $environmentView ? 'environment' : 'organization',
            'organizationName' => $environmentView ? null : $filter->name,
            'environmentProviders' => $environmentView || $managesEnvironment
                ? array_map(fn (Connection $connection): array => $this->row($connection), $environment)
                : [],
            // "Who is it for?" on the organization console of a single-tenant install: every
            // sign-in page, or only this organization's.
            'ownerChoice' => $managesEnvironment,
            'organizationProviders' => $environmentView
                ? array_map(fn (Connection $connection): array => $this->row($connection, $owners[(string) $connection->organization_id] ?? (string) $connection->organization_id, in_array($connection->provider, $environmentKeys, true)), $own)
                : [],
            'optOuts' => $environmentView ? $this->optOutRows($providers, $owners) : [],
            'page' => $environmentView || $organizationId === null ? [] : $this->pageRows($providers, $organizationId, $environment, $own),
            'available' => array_map(fn (ProviderTemplate $option): array => [
                'key' => $option->key,
                'name' => $option->name,
                'protocol' => $option->isOidc() ? 'OpenID Connect' : 'OAuth 2.0',
                // The filter rides along, so the setup form opens for the organization the
                // list is narrowed to.
                'href' => $this->url('social-providers', array_filter([
                    'provider' => $option->key,
                    'organization' => $this->organizationFilterProps($filter) === null ? null : $organizationId,
                ])),
            ], array_values(array_filter(
                ProviderCatalog::withCapability(ProviderCapability::Login),
                static fn (ProviderTemplate $t): bool => ! in_array($t->key, $taken, true),
            ))),
            'template' => $template === null ? null : [
                ...$this->templateProps($template),
                'reservedId' => $reserved = $this->reservedId($request, $template),
                'redirectUri' => SocialProviderFields::callbackUriFor($template, $reserved),
            ],
            'editing' => $editing,
            'organizationFilter' => $this->organizationFilterProps($filter),
            // "Who is it for?" on the setup form, on the environment console: every sign-in
            // page in the environment unless an organization is chosen — prefilled and
            // locked to the one the list is filtered to.
            'organization' => $this->organizationPicker(allowsEnvironment: true),
            'environmentHref' => $this->scope->plane() === ConsolePlane::Environment && ! $environmentView
                ? route('environment.social-providers')
                : null,
            'indexHref' => $this->url('social-providers', $environmentView || $this->organizationFilterProps($filter) === null ? null : ['organization' => $organizationId]),
            'storeHref' => $this->url('social-providers.store'),
            'help' => HelpProps::for(HelpTopic::SocialSignIn),
        ]);
    }

    /**
     * Enable a provider through the ACTION the management API runs ({@see EnableSocialProvider}):
     * the catalogue checks, the one-per-provider rule and discovery are the action's, so a
     * provider enabled here and one enabled by a key are refused and recorded alike.
     */
    public function store(EnableSocialProviderRequest $request): RedirectResponse
    {
        $this->scope->assertMayAdminister();
        app(VerifiedEmailGate::class)->require('add a sign-in provider');

        $template = SocialProviderFields::loginTemplate($request->provider());
        $parameters = $template === null ? [] : array_map(static fn ($parameter): string => $parameter->key, $template->parameters);

        // A refusal about a parameter lands on that parameter's own field.
        $fields = ['provider' => 'provider', 'client_id' => 'clientId', 'client_secret' => 'clientSecret', 'scopes' => 'scopes', 'reserved_id' => 'clientId', 'organization_id' => 'organization'];

        foreach ($parameters as $key) {
            $fields['parameters.'.$key] = 'parameters.'.$key;
        }

        // The environment's own unless the form named an organization — the organization
        // console's always being its own, except where its administrators are the
        // environment's and the form said "every sign-in page".
        $forEnvironment = $this->managesEnvironmentHere() && $request->boolean('forEnvironment');
        $organizationId = $forEnvironment ? null : $this->chosenOrganizationId($request, allowsEnvironment: true);

        $result = $this->act(EnableSocialProvider::class, [
            ...($organizationId === null ? ['environment_wide' => true] : ['organization_id' => $organizationId]),
            'provider' => $request->provider(),
            'client_id' => $request->clientId(),
            'client_secret' => $request->clientSecret(),
            'parameters' => array_intersect_key($request->parameters(), array_flip($parameters)),
            'scopes' => $request->scopes(),
            'reserved_id' => $request->reservedId(),
        ], $fields, 'clientId', asEnvironment: $forEnvironment);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var Connection $enabled */
        $enabled = $result->value;
        $request->session()->forget(self::RESERVED.$request->provider());

        $where = $enabled->organization_id === null
            ? 'every sign-in page in this environment'
            : (($this->scope->organizationNames([$enabled->organization_id])[$enabled->organization_id] ?? 'this organization').'’s sign-in page');

        return to_route($this->scope->routeName('social-providers'), $this->scope->plane() === ConsolePlane::Environment && $enabled->organization_id !== null
            ? ['organization' => $enabled->organization_id]
            : [])
            ->with('status', $enabled->name.' is now offered on '.$where.'.');
    }

    /** New credentials, values or scopes, through {@see UpdateSocialProvider}. */
    public function update(UpdateSocialProviderRequest $request, string $connection): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $environment = $this->targetsEnvironment($connection);

        $result = $this->act(UpdateSocialProvider::class, [
            'id' => $connection,
            'organization_id' => $environment ? null : $this->routeOrganizationId(),
            ...$request->changes(),
        ], ['client_id' => 'clientId', 'client_secret' => 'clientSecret', 'scopes' => 'scopes', 'parameters' => 'parameters'], 'clientId', asEnvironment: $environment);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var Connection $updated */
        $updated = $result->value;

        // Back to the list the form was opened from: one organization's, when it was.
        $backToOrganization = $this->scope->plane() === ConsolePlane::Environment
            && $this->scope->organizationId() === null
            && $updated->organization_id !== null
            && $request->boolean('fromOrganization');

        return redirect($this->url('social-providers', $backToOrganization ? ['organization' => $updated->organization_id] : null))
            ->with('status', $updated->name.' is saved.');
    }

    public function enable(string $connection): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $environment = $this->targetsEnvironment($connection);
        $result = $this->act(TurnOnSocialProvider::class, ['id' => $connection, 'organization_id' => $environment ? null : $this->routeOrganizationId()], asEnvironment: $environment);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var Connection $enabled */
        $enabled = $result->value;

        return back()->with('status', $enabled->name.' is on again.');
    }

    public function disable(string $connection): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $environment = $this->targetsEnvironment($connection);
        $result = $this->act(TurnOffSocialProvider::class, ['id' => $connection, 'organization_id' => $environment ? null : $this->routeOrganizationId()], asEnvironment: $environment);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var Connection $disabled */
        $disabled = $result->value;

        return back()->with('status', $disabled->organization_id === null
            ? $disabled->name.' is off on every sign-in page. Its credentials are kept, so you can turn it back on.'
            : $disabled->name.' is off on this organization’s sign-in page. Its credentials are kept, so you can turn it back on.');
    }

    public function destroy(string $connection): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        /*
         * THE ORGANIZATION IS IN THE QUERY — the action's — not in an `if` after it.
         * Another tenant's provider is a 404, not a button this administrator is failing to
         * press: it is a row they have no business learning exists.
         */
        $environment = $this->targetsEnvironment($connection);

        $result = $this->act(RemoveSocialProvider::class, [
            'id' => $connection,
            // The member's own organization, which the action holds the lookup to; on the
            // environment console, whose administrator holds every organization, none — and
            // none for the environment's own provider on a console that administers it.
            'organization_id' => $environment ? null : $this->routeOrganizationId(),
        ], asEnvironment: $environment);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var Connection $removed */
        $removed = $result->value;

        return back()->with(
            'status',
            $removed->name.' is no longer offered. Anyone who signed in with it keeps their account and can still use their password.',
        );
    }

    /**
     * Whether one organization's page offers one of the environment's providers, through
     * {@see SetSocialProviderInheritance}.
     */
    public function inherit(Request $request, string $provider): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $offered = $request->boolean('offered');

        $result = $this->act(SetSocialProviderInheritance::class, [
            'provider' => $provider,
            'organization_id' => $this->chosenOrganizationId($request),
            'offered' => $offered,
        ]);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        $name = ProviderCatalog::find($provider)->name ?? $provider;

        return back()->with('status', $offered
            ? $name.' from the environment is offered on this organization’s sign-in page again.'
            : $name.' from the environment is no longer offered on this organization’s sign-in page.');
    }

    /**
     * Whether this is an ORGANIZATION console whose administrators are the environment's —
     * a single-tenant install's ({@see ConsoleScope::administersEnvironment()}).
     * The environment console administers it by being the environment console.
     */
    private function managesEnvironmentHere(): bool
    {
        return $this->scope->plane() === ConsolePlane::Organization && $this->scope->administersEnvironment();
    }

    /**
     * Whether a write names the ENVIRONMENT's provider from an organization console that
     * administers it — the one case its action runs for the environment rather than
     * confined to this organization. Anything else keeps the organization's narrowing, and
     * another organization's id stays a 404.
     */
    private function targetsEnvironment(string $connection): bool
    {
        return $this->managesEnvironmentHere()
            && Connection::query()->whereKey($connection)->whereNull('organization_id')->whereNotNull('provider')->exists();
    }

    /**
     * The id the setup form reserves so it can show the real redirect URI, kept in the
     * session per provider: a reload — or a second look after registering the URI in the
     * provider's own console — shows the same one. Spent when the provider is saved.
     */
    private function reservedId(Request $request, ProviderTemplate $template): string
    {
        $key = self::RESERVED.$template->key;
        $held = $request->session()->get($key);

        if (is_string($held) && Str::isUlid($held) && Connection::query()->withoutGlobalScopes()->whereKey($held)->doesntExist()) {
            return $held;
        }

        $id = strtolower((string) Str::ulid());
        $request->session()->put($key, $id);

        return $id;
    }

    /**
     * One provider row, with every control its owner's administrator has.
     *
     * @return array<string, mixed>
     */
    private function row(Connection $connection, ?string $organization = null, bool $replacesEnvironment = false): array
    {
        return [
            'id' => $connection->id,
            'name' => $connection->name,
            'provider' => $connection->provider,
            'protocol' => $connection->type === ConnectionType::OAuth2 ? 'OAuth 2.0' : 'OpenID Connect',
            'enabled' => $connection->isActive(),
            /*
             * THE REAL REDIRECT URI. The one value the provider must be given, and the
             * sign-in fails with an error naming the client id rather than the URI when it
             * is wrong — which reads as a credential problem and gets debugged as one.
             */
            'callbackUri' => SocialProviderFields::callbackUri($connection),
            'scopes' => SocialProviderFields::extraScopes($connection),
            'organization' => $organization,
            'replacesEnvironment' => $replacesEnvironment,
            'editHref' => $this->url('social-providers', array_filter([
                'edit' => $connection->id,
                'organization' => $this->scope->plane() === ConsolePlane::Environment && $this->scope->organizationId() === null && request()->query('organization') !== null
                    ? $connection->organization_id
                    : null,
            ])),
            'enableHref' => $this->url('social-providers.enable', $connection->id),
            'disableHref' => $this->url('social-providers.disable', $connection->id),
            'removeHref' => $this->url('social-providers.destroy', $connection->id),
        ];
    }

    /**
     * What one organization's sign-in page shows, button by button: its own (in the
     * environment's place where both exist), then each of the environment's — offered,
     * turned off by this organization, replaced by its own, or off for everyone.
     *
     * @param  list<Connection>  $environment
     * @param  list<Connection>  $own
     * @return list<array<string, mixed>>
     */
    private function pageRows(SignInProviders $providers, string $organizationId, array $environment, array $own): array
    {
        $ownKeys = array_map(static fn (Connection $connection): string => (string) $connection->provider, $own);
        $hidden = $providers->notInheritedBy($organizationId);
        $inheritHref = fn (string $provider): string => $this->url('social-providers.inherit', $provider);

        $rows = array_map(fn (Connection $connection): array => [
            ...$this->row($connection, null, in_array($connection->provider, array_map(static fn (Connection $e): string => (string) $e->provider, $environment), true)),
            'source' => 'organization',
            'state' => $connection->isActive() ? 'offered' : 'off',
            'inheritHref' => null,
        ], $own);

        foreach ($environment as $connection) {
            $key = (string) $connection->provider;

            $rows[] = [
                'id' => $connection->id,
                'name' => $connection->name,
                'provider' => $connection->provider,
                'protocol' => $connection->type === ConnectionType::OAuth2 ? 'OAuth 2.0' : 'OpenID Connect',
                'enabled' => $connection->isActive(),
                'callbackUri' => SocialProviderFields::callbackUri($connection),
                'source' => 'environment',
                'state' => match (true) {
                    in_array($key, $ownKeys, true) => 'replaced',
                    ! $connection->isActive() => 'off',
                    in_array($key, $hidden, true) => 'hidden',
                    default => 'offered',
                },
                // Turning the environment's off for this page, or back on — not offered for
                // one this organization replaced with its own, where it would change nothing.
                'inheritHref' => in_array($key, $ownKeys, true) ? null : $inheritHref($key),
            ];
        }

        return $rows;
    }

    /**
     * Which organizations turned which of the environment's providers off for their page.
     *
     * @param  array<string, string>  $owners
     * @return list<array{organizationId: string, organization: string, provider: string, name: string, inheritHref: string}>
     */
    private function optOutRows(SignInProviders $providers, array $owners): array
    {
        $rows = [];

        foreach ($providers->optOuts() as $organizationId => $keys) {
            foreach ($keys as $key) {
                $rows[] = [
                    'organizationId' => $organizationId,
                    'organization' => $owners[$organizationId] ?? $organizationId,
                    'provider' => $key,
                    'name' => ProviderCatalog::find($key)->name ?? $key,
                    'inheritHref' => $this->url('social-providers.inherit', $key),
                ];
            }
        }

        return $rows;
    }

    /**
     * The change-credentials form for `?edit={id}`: what is on file that is not a secret,
     * and the shape of what the provider asks for. Never the client secret, never Apple's
     * private key — blank keeps them.
     *
     * @return array<string, mixed>|null
     */
    private function editing(Request $request, ?string $organizationId): ?array
    {
        $id = $request->string('edit')->toString();

        if ($id === '') {
            return null;
        }

        $connection = Connection::query()
            ->whereKey($id)
            ->whereNotNull('provider')
            ->when($this->scope->plane() === ConsolePlane::Organization || $this->scope->organizationId() !== null,
                fn ($query) => $this->managesEnvironmentHere()
                    // The organization's own, or the environment's where this console administers it.
                    ? $query->where(fn ($owner) => $owner->where('organization_id', $this->scope->requireOrganizationId())->orWhereNull('organization_id'))
                    : $query->where('organization_id', $this->scope->requireOrganizationId()))
            ->first();

        $template = $connection === null ? null : SocialProviderFields::loginTemplate((string) $connection->provider);

        if ($connection === null || $template === null) {
            return null;
        }

        $config = app(Connections::class)->config($connection);
        $props = $this->templateProps($template);

        return [
            ...$props,
            'id' => $connection->id,
            'organization' => $connection->organization_id === null
                ? null
                : ($this->scope->organizationNames([$connection->organization_id])[$connection->organization_id] ?? null),
            'redirectUri' => SocialProviderFields::callbackUri($connection),
            'clientId' => is_string($config['client_id'] ?? null) ? $config['client_id'] : '',
            // Every provider value that is not key material, as it is on file.
            'values' => array_map(
                static fn (array $parameter): string => $parameter['multiline'] ? '' : (is_string($config[$parameter['key']] ?? null) ? $config[$parameter['key']] : ''),
                array_column($props['parameters'], null, 'key'),
            ),
            'scopes' => implode(' ', SocialProviderFields::extraScopes($connection)),
            'updateHref' => $this->url('social-providers.update', $connection->id),
            'fromOrganization' => $organizationId !== null,
        ];
    }

    /**
     * @return array{key: string, name: string, protocol: string, documentationUrl: string|null, setupSteps: list<string>, parameters: list<array{key: string, label: string, help: string, example: string, multiline: bool}>, mintsItsOwnSecret: bool}
     */
    private function templateProps(ProviderTemplate $template): array
    {
        return [
            'key' => $template->key,
            'name' => $template->name,
            'protocol' => $template->isOidc() ? 'OpenID Connect' : 'OAuth 2.0',
            'documentationUrl' => $template->documentationUrl,
            'setupSteps' => $template->setupSteps,
            'parameters' => array_map(static fn ($parameter): array => [
                'key' => $parameter->key,
                'label' => $parameter->label,
                'help' => $parameter->help,
                'example' => $parameter->example,
                // A PEM key is four lines, not a word: the form asks for it in a textarea —
                // and never shows it back. Decided here rather than by the component
                // sniffing the label for "private".
                'multiline' => str_contains(mb_strtolower($parameter->label), 'key')
                    && str_contains(mb_strtolower($parameter->label), 'private'),
            ], $template->parameters),
            /*
             * Apple's client id is the SERVICES ID, not the App ID, and saying "Client ID"
             * here is the single most common way a first attempt fails: both exist in Apple's
             * console, both look like a reverse domain, and only one of them works.
             */
            'mintsItsOwnSecret' => ! SocialProviderFields::takesSecret($template),
        ];
    }
}
