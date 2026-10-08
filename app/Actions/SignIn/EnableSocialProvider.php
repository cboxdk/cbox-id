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
use App\Platform\Actions\OrganizationTarget;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ClientSecretKind;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Exceptions\InvalidAssertion;
use Cbox\Id\Federation\OidcDiscovery;
use Throwable;

/**
 * Offer a social login provider on one organization's sign-in page, from the catalogue.
 *
 * Everything an administrator used to have to know — Google's issuer, that Entra's names the
 * directory, that GitHub is not an OpenID Provider at all — is catalogue data. What is left
 * is genuinely the caller's: the client id and secret from their own account with that
 * provider, and the few values a provider asks for besides. The secret is INPUT ONLY: it is
 * sealed into the connection and never returned, by this or any other action.
 *
 * One connection per provider per organization: two would each render a button with the
 * same name, and nothing on the sign-in page could tell a person which to press. An OpenID
 * provider is discovered NOW, so a mistyped Okta domain fails while somebody is still
 * looking at it rather than later, for a user. Created as a draft and then activated, so a
 * half-saved provider never appears as a button.
 */
#[AsAction(
    name: 'signin.social.set',
    summary: 'Enable a social login provider (Google, GitHub, Apple…) for one organization with its client credentials. The secret is never returned.',
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
        private OidcDiscovery $discovery,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->required()->max(64)->describe('The organization whose sign-in page offers it.'),
            Field::string('provider')->required()->max(100)->describe('The catalogue key: google, microsoft, okta, auth0, keycloak, gitlab, slack, github, discord, apple, facebook.'),
            Field::string('client_id')->required()->max(400)->describe('The client id from your own account with the provider. For Apple, the Services ID.'),
            Field::string('client_secret')->nullable()->max(5000)->describe('The client secret. Write-only. Not used by Apple, which signs its own.'),
            SocialProviderFields::parametersField(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $template = SocialProviderFields::loginTemplate($context->string('provider'))
            ?? throw ActionRefused::because('unknown_provider', 'That provider is not one that can be offered for sign-in.', 'provider');

        // Apple hands out key material instead of a client secret; demanding one anyway made
        // the one provider shaped specially the one nobody could finish enabling.
        $secretRequired = $template->secretKind !== ClientSecretKind::SignedJwt;
        $secret = trim($context->string('client_secret'));

        if ($secretRequired && $secret === '') {
            throw ActionRefused::because('client_secret_required', 'The client secret is required.', 'client_secret');
        }

        // EVERY MISSING PARAMETER AT ONCE. Reporting the first and stopping would walk
        // somebody through Apple's three fields one round-trip at a time.
        $given = $context->array('parameters');
        $values = [];
        $missing = [];

        foreach ($template->parameters as $parameter) {
            $value = $given[$parameter->key] ?? null;
            $value = is_string($value) ? trim($value) : '';

            if ($value === '') {
                $missing['parameters.'.$parameter->key] = $parameter->label.' is required.';
            } else {
                $values[$parameter->key] = $value;
            }
        }

        if ($missing !== []) {
            throw ActionRefused::onFields('missing_parameters', $missing);
        }

        $organizationId = (string) OrganizationTarget::check($context, $context->string('organization_id'));

        foreach ($this->connections->catalogueProvidersFor($organizationId) as $existing) {
            if ($existing->provider === $template->key) {
                throw ActionRefused::because('already_enabled', $template->name.' is already enabled. Remove it first if you want to use different credentials.', 'client_id');
            }
        }

        $config = [
            'provider' => $template->key,
            'client_id' => trim($context->string('client_id')),
            ...($secretRequired ? ['client_secret' => $secret] : []),
            ...$values,
        ];

        if ($template->isOidc()) {
            $issuer = $template->issuerFor($values);

            if ($issuer === null) {
                throw ActionRefused::because('incomplete_parameters', 'Fill in every field before enabling '.$template->name.'.', 'client_id');
            }

            $config['issuer'] = $issuer;

            try {
                $document = $this->discovery->fromIssuer($issuer);
                $config['authorization_endpoint'] = $document->authorizationEndpoint;
                $config['token_endpoint'] = $document->tokenEndpoint;
                $config['jwks_uri'] = $document->jwksUri;
            } catch (Throwable $e) {
                throw ActionRefused::because('discovery_failed', 'We could not reach '.$template->name.' at '.$issuer.' — check the details. ('.$e->getMessage().')', 'client_id');
            }
        }

        try {
            $connection = $this->connections->create(
                organizationId: $organizationId,
                type: $template->isOidc() ? ConnectionType::Oidc : ConnectionType::OAuth2,
                name: $template->name,
                config: $config,
                provider: $template->key,
            );
        } catch (InvalidAssertion $e) {
            throw ActionRefused::because('invalid_provider', $e->getMessage(), 'client_id');
        }

        $this->connections->activate($organizationId, $connection->id);

        $fresh = $this->connections->byId($connection->id) ?? $connection;

        return ActionResult::item($fresh, SocialProviderFields::present($fresh));
    }
}
