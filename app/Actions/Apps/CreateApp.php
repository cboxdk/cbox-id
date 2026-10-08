<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Http\Resources\Environment\AppResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\AppKind;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Exceptions\InvalidClientMetadata;
use Cbox\Id\OAuthServer\Exceptions\ScopeNotGrantable;
use Cbox\Id\OAuthServer\ValueObjects\ClientBlueprint;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\OAuthServer\ValueObjects\ScopeHolder;
use Illuminate\Validation\ValidationException;

/**
 * Register an app, from a {@see ClientBlueprint} or from a short form.
 *
 * A BLUEPRINT is what `apps.blueprint` returns in another environment: send it as
 * `blueprint` to create the same app here (promote staging to production). Its redirect
 * URIs usually name the environment it came from, so `redirect_uris`,
 * `post_logout_redirect_uris` and `name` beside it replace the blueprint's own. It is
 * IMPORTED, and the trail says so (`source: blueprint`).
 *
 * The SHORT FORM is the console's "Create app": a `name` and a `type` — `web`, `spa`, `cli`,
 * `service`, `agent` — which decides the client type, the grants and the default scopes.
 * `advanced` takes `client_type` and `grant_types` by hand. It is REGISTERED, the way the
 * console always has, with its lists as given.
 *
 * Either way it becomes one blueprint, checked by the framework's own rules before anything
 * is written, and the registry records `app.created` attributed to whoever asked.
 *
 * CRITICAL, because it mints a credential: a confidential app's `client_secret` is in the
 * answer ONCE — only its hash is kept, and an idempotent replay returns everything but it.
 */
#[AsAction(
    name: 'apps.create',
    summary: 'Register an app (OAuth client) from a short form (name and type) or from another environment\'s blueprint. A confidential app\'s client_secret is returned once.',
    scope: 'apps:write',
    danger: Danger::Critical,
    schema: 'App',
    tag: 'Apps',
    rest: ['POST', '/apps'],
    status: 201,
    consoleRoutes: ['clients.store', 'environment.clients.store', 'environment.get-started.app'],
    consoleGate: ConsoleGate::Administer,
    redact: ['client_secret'],
)]
final readonly class CreateApp implements Action
{
    public function __construct(
        private ClientRegistry $clients,
        private EnvironmentContext $environments,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::object('blueprint', [])->describe('A blueprint document from `GET /apps/{id}/blueprint`. Left out, the short form below describes the app.'),
            Field::string('name')->max(190)->describe('Required without a blueprint; beside one, it replaces the blueprint\'s.'),
            Field::string('type')->oneOf(array_map(static fn (AppKind $kind): string => $kind->value, AppKind::cases()))->describe('The kind of app. Default `web`.'),
            Field::string('client_type')->oneOf(array_map(static fn (ClientType $type): string => $type->value, ClientType::cases()))->describe('Required for `advanced`.'),
            Field::list('grant_types', Field::string('grant')->max(100))->describe('Required for `advanced`.'),
            AppFields::uris('redirect_uris'),
            AppFields::uris('post_logout_redirect_uris'),
            Field::list('scopes', Field::string('scope')->max(128))->max(100)->describe('Left out, the kind\'s default scopes.'),
            Field::boolean('first_party')->describe('Skips the consent screen. Default false.'),
            Field::string('manifest_url')->nullable()->max(500)->format('uri')->describe('Where the app publishes its roles-and-permissions manifest.'),
            Field::string('organization_id')->nullable()->max(64)->describe('The owning organization; null for the environment\'s own app.'),
            Field::object('jwks', [])->nullable()->describe('A public JWK Set, for a `private_key_jwt` app. Never part of a blueprint.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = $context->nullableString('organization_id');

        AppFields::assertMayOwn($context, $organizationId);

        $document = $context->input['blueprint'] ?? null;

        try {
            $blueprint = is_array($document) ? $this->fromDocument($document, $context) : $this->fromShortForm($context);
            $blueprint->assertValid();
        } catch (InvalidClientMetadata $refused) {
            throw AppFields::invalid($refused, is_array($document) ? 'blueprint' : 'redirect_uris');
        }

        AppFields::refuseReserved($context, $blueprint->scopes);

        $jwks = $this->jwks($context);

        try {
            $registered = is_array($document)
                ? $this->clients->import($blueprint, $organizationId, $jwks, $context->actor())
                : $this->clients->register($this->newClient($blueprint, $context, $organizationId, $jwks), $context->actor());
        } catch (InvalidClientMetadata $refused) {
            throw AppFields::invalid($refused);
        } catch (ScopeNotGrantable $refused) {
            throw AppFields::notGrantable(
                $refused,
                new ScopeHolder($this->environments->current()?->environmentKey(), $organizationId),
            );
        }

        return ActionResult::item($registered, AppResource::from($registered->client, $registered->secret));
    }

    /**
     * @param  array<array-key, mixed>  $document
     *
     * @throws InvalidClientMetadata
     */
    private function fromDocument(array $document, ActionContext $context): ClientBlueprint
    {
        $blueprint = ClientBlueprint::fromArray($document);

        if ($context->has('name')) {
            $blueprint = $blueprint->withName(trim($context->string('name')));
        }

        if ($context->has('redirect_uris')) {
            $blueprint = $blueprint->withRedirectUris($this->strings($context, 'redirect_uris'));
        }

        if ($context->has('post_logout_redirect_uris')) {
            $blueprint = $blueprint->withPostLogoutRedirectUris($this->strings($context, 'post_logout_redirect_uris'));
        }

        return $blueprint;
    }

    /**
     * The console's form as a blueprint. What a form request would have refused — no name,
     * an `advanced` app without its type or grants — is refused as validation, the same
     * `validation_failed` it always was.
     *
     * @throws InvalidClientMetadata
     * @throws ValidationException
     */
    private function fromShortForm(ActionContext $context): ClientBlueprint
    {
        $name = trim($context->string('name'));
        $kind = AppKind::tryFrom($context->string('type')) ?? AppKind::WebApp;
        $missing = [];

        if ($name === '') {
            $missing['name'] = 'The name field is required when blueprint is not present.';
        }

        if ($kind === AppKind::Advanced && ! $context->has('client_type')) {
            $missing['client_type'] = 'The client type field is required when type is advanced.';
        }

        if ($kind === AppKind::Advanced && ! $context->has('grant_types')) {
            $missing['grant_types'] = 'The grant types field is required when type is advanced.';
        }

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }

        $manifestUrl = $context->nullableString('manifest_url');

        if ($manifestUrl !== null && filter_var($manifestUrl, FILTER_VALIDATE_URL) === false) {
            throw InvalidClientMetadata::metadata('manifest_url must be an absolute URL or null');
        }

        return new ClientBlueprint(
            name: $name,
            type: $kind === AppKind::Advanced
                ? (ClientType::tryFrom($context->string('client_type')) ?? ClientType::Confidential)
                : $kind->clientType(),
            grantTypes: $kind === AppKind::Advanced ? $this->strings($context, 'grant_types') : $kind->grantTypes(),
            redirectUris: $this->strings($context, 'redirect_uris'),
            postLogoutRedirectUris: $this->strings($context, 'post_logout_redirect_uris'),
            scopes: $context->has('scopes') ? $this->strings($context, 'scopes') : $kind->defaultScopes(),
            firstParty: $context->boolean('first_party'),
            manifestUrl: $manifestUrl,
        );
    }

    /**
     * The short form as the registry registers it — with its lists in the order they were
     * given. The blueprint sorts them, which is right for a document meant to diff cleanly
     * and wrong here: the kind an app is read back as ({@see AppKind::forClient()}) is
     * matched against the grants in the order its preset states them.
     *
     * @param  array<string, mixed>|null  $jwks
     */
    private function newClient(ClientBlueprint $blueprint, ActionContext $context, ?string $organizationId, ?array $jwks): NewClient
    {
        $kind = AppKind::tryFrom($context->string('type')) ?? AppKind::WebApp;

        return new NewClient(
            name: $blueprint->name,
            type: $blueprint->type,
            redirectUris: $this->strings($context, 'redirect_uris'),
            grantTypes: $kind === AppKind::Advanced ? $this->strings($context, 'grant_types') : $kind->grantTypes(),
            scopes: $context->has('scopes') ? $this->strings($context, 'scopes') : $kind->defaultScopes(),
            firstParty: $blueprint->firstParty,
            organizationId: $organizationId,
            jwks: $jwks,
            postLogoutRedirectUris: $this->strings($context, 'post_logout_redirect_uris'),
            manifestUrl: $blueprint->manifestUrl,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jwks(ActionContext $context): ?array
    {
        $jwks = $context->input['jwks'] ?? null;

        if (! is_array($jwks)) {
            return null;
        }

        $out = [];

        foreach ($jwks as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function strings(ActionContext $context, string $key): array
    {
        return array_values(array_filter(
            $context->array($key),
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));
    }
}
