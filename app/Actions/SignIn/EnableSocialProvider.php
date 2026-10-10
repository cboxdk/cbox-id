<?php

declare(strict_types=1);

namespace App\Actions\SignIn;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\SignInProviders;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Exceptions\InvalidAssertion;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\ValueObjects\DiscoveredOidcProvider;
use Illuminate\Database\Eloquent\Builder;

/**
 * Offer a social login provider from the catalogue — on EVERY sign-in page in the
 * environment, or on one organization's.
 *
 * THE ENVIRONMENT IS THE COMMON CASE. "Turn on Google for my app" is one set of credentials
 * every organization inherits, which is how other identity platforms configure social
 * sign-in. An organization's own provider is the exception: it replaces the environment's
 * for that provider on that organization's page (see {@see SignInProviders} for the
 * precedence). Which one is meant is SAID, never inferred: `environment_wide: true` or an
 * `organization_id`, the way every other "environment or organization" record here is
 * created — a null that arrives from a forgotten field would otherwise put a provider on
 * every customer's page.
 *
 * Everything an administrator used to have to know — Google's issuer, that Entra's names the
 * directory, that GitHub is not an OpenID Provider at all — is catalogue data. What is left
 * is genuinely the caller's: the client id and secret from their own account with that
 * provider, and the few values a provider asks for besides. The secret is INPUT ONLY: it is
 * sealed into the connection and never returned, by this or any other action.
 *
 * One connection per provider per owner: two would each render a button with the same name.
 * An OpenID provider is discovered NOW, so a mistyped Okta domain fails while somebody is
 * still looking at it rather than later, for a user. Created as a draft and then activated,
 * so a half-saved provider never appears as a button — and activation is what announces it
 * (`connection.activated`, to the trail and to webhooks).
 *
 * `reserved_id` lets the console show the REAL redirect URI before anything is saved: the
 * URI contains the connection's id, and the provider's own console wants it first.
 */
#[AsAction(
    name: 'signin.social.set',
    summary: 'Enable a social login provider (Google, GitHub, Apple…) for the whole environment or for one organization, with its client credentials. The secret is never returned.',
    scope: 'signin:write',
    danger: Danger::Critical,
    schema: 'SocialProvider',
    tag: 'Sign-in',
    rest: ['POST', '/sign-in/social-providers'],
    status: 201,
    consoleRoutes: ['social-providers.store', 'environment.social-providers.store'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class EnableSocialProvider implements Action
{
    public function __construct(
        private Connections $connections,
        private SignInProviders $providers,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            ...EnterpriseReach::ownerFields('the provider'),
            Field::string('provider')->required()->max(100)->describe('The catalogue key: google, microsoft, okta, auth0, keycloak, gitlab, slack, github, discord, apple, facebook, linkedin, bitbucket, xero, intuit.'),
            Field::string('client_id')->required()->max(400)->describe('The client id from your own account with the provider. For Apple, the Services ID.'),
            Field::string('client_secret')->nullable()->max(5000)->describe('The client secret. Write-only. Not used by Apple, which signs its own.'),
            SocialProviderFields::parametersField(),
            SocialProviderFields::scopesField(),
            Field::string('reserved_id')->nullable()->max(26)->describe('A ULID to create the provider under, so its redirect URI (which contains the id) can be registered with the provider first. Left out, one is minted.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $template = SocialProviderFields::loginTemplate($context->string('provider'))
            ?? throw ActionRefused::because('unknown_provider', 'That provider is not one that can be offered for sign-in.', 'provider');

        // Apple hands out key material instead of a client secret; demanding one anyway made
        // the one provider shaped specially the one nobody could finish enabling.
        $secretRequired = SocialProviderFields::takesSecret($template);
        $secret = trim($context->string('client_secret'));

        if ($secretRequired && $secret === '') {
            throw ActionRefused::because('client_secret_required', 'The client secret is required.', 'client_secret');
        }

        $values = SocialProviderFields::parameters($context, $template);
        $scopes = SocialProviderFields::scopes($context);

        $organizationId = EnterpriseReach::owner($context, 'a social provider');

        // ANY status: a provider that is turned off is still this owner's, and a second one
        // beside it would be a second row for one button.
        $existing = Connection::query()
            ->where('provider', $template->key)
            ->when(
                $organizationId === null,
                fn (Builder $query): Builder => $query->whereNull('organization_id'),
                fn (Builder $query): Builder => $query->where('organization_id', $organizationId),
            )
            ->exists();

        if ($existing) {
            throw ActionRefused::because('already_enabled', $template->name.' is already set up '.($organizationId === null ? 'for the environment' : 'for this organization').'. Change its credentials, or remove it first.', 'client_id');
        }

        $config = [
            'provider' => $template->key,
            'client_id' => trim($context->string('client_id')),
            ...($secretRequired ? ['client_secret' => $secret] : []),
            ...$values,
            // The document is stored WHOLE ({@see DiscoveredOidcProvider::toConfig()}): the
            // UserInfo endpoint a provider whose id_token carries no address is read from,
            // and the token endpoint auth method its document allows.
            ...SocialProviderFields::discover($template, $values),
            ...array_filter(SocialProviderFields::scopeConfig($template, $scopes), static fn (array $list): bool => $list !== []),
        ];

        try {
            $connection = $this->providers->create(
                organizationId: $organizationId,
                provider: $template->key,
                type: $template->isOidc() ? ConnectionType::Oidc : ConnectionType::OAuth2,
                name: $template->name,
                config: $config,
                id: $context->nullableString('reserved_id'),
            );
        } catch (InvalidAssertion $e) {
            throw ActionRefused::because('invalid_provider', $e->getMessage(), $context->nullableString('reserved_id') === null ? 'client_id' : 'reserved_id');
        }

        $this->connections->activate($organizationId, $connection->id);

        $fresh = $this->connections->byId($connection->id) ?? $connection;

        return ActionResult::item($fresh, SocialProviderFields::present($fresh));
    }
}
