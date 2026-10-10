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
use App\Platform\SignInAudit;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;

/**
 * Change a social provider's credentials, its provider-specific values, or the extra scopes
 * it asks for — rotating a client secret without taking the button away.
 *
 * Before this the only way to rotate a secret was to remove the provider and enable it again,
 * which gave it a new id — and with it a new redirect URI the provider had never heard of, so
 * the first person to press the button after a routine rotation got an error page.
 *
 * Only what is sent changes. A secret left out (or blank) keeps the sealed one, which is never
 * read back to anyone. An OpenID provider is re-discovered whenever its values change, so its
 * endpoints cannot drift from its issuer. Critical: on the environment's provider this is the
 * credential behind a button on every sign-in page.
 */
#[AsAction(
    name: 'signin.social.update',
    summary: 'Change a social login provider\'s client credentials, provider values or extra scopes. Secrets left out keep the ones on file.',
    scope: 'signin:write',
    danger: Danger::Critical,
    schema: 'SocialProvider',
    tag: 'Sign-in',
    rest: ['PATCH', '/sign-in/social-providers/{id}'],
    consoleRoutes: ['social-providers.update', 'environment.social-providers.update'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class UpdateSocialProvider implements Action
{
    public function __construct(
        private Connections $connections,
        private SecretBox $secretBox,
        private SignInAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The provider\'s id.'),
            Field::string('organization_id')->nullable()->max(64)->describe('Only change it if it is this organization\'s; anything else is a 404.'),
            Field::string('client_id')->max(400)->describe('A new client id. For Apple, the Services ID.'),
            Field::string('client_secret')->nullable()->max(5000)->describe('A new client secret. Write-only; left out or blank keeps the one on file.'),
            SocialProviderFields::parametersField(),
            SocialProviderFields::scopesField(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $connection = SocialProviderFields::reachable($context);
        $template = SocialProviderFields::loginTemplate((string) $connection->provider)
            ?? throw ActionRefused::because('unknown_provider', 'That provider is no longer in the catalogue; remove it instead.');

        $current = $this->connections->config($connection);
        $config = $current;
        $changed = [];

        $clientId = trim($context->string('client_id'));

        if ($context->has('client_id')) {
            if ($clientId === '') {
                throw ActionRefused::because('client_id_required', 'The client id cannot be blank.', 'client_id');
            }

            if ($clientId !== ($current['client_id'] ?? null)) {
                $config['client_id'] = $clientId;
                $changed[] = 'client_id';
            }
        }

        $secret = trim($context->string('client_secret'));

        if ($secret !== '' && SocialProviderFields::takesSecret($template)) {
            $config['client_secret'] = $secret;
            $changed[] = 'client_secret';
        }

        if ($context->has('parameters')) {
            $onFile = array_filter(
                array_intersect_key($current, array_flip(array_map(static fn ($parameter): string => $parameter->key, $template->parameters))),
                is_string(...),
            );
            $values = SocialProviderFields::parameters($context, $template, $onFile);

            if ($values !== $onFile) {
                // Re-discovered, the endpoints are the new document's — none kept from before.
                $config = [...array_diff_key($config, array_flip(['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri', 'userinfo_endpoint', 'token_endpoint_auth_method'])), ...$values, ...SocialProviderFields::discover($template, $values)];
                $changed[] = 'parameters';
            }
        }

        if ($context->has('scopes')) {
            $scopes = SocialProviderFields::scopes($context);

            if ($scopes !== SocialProviderFields::extraScopes($connection)) {
                $config = [...array_diff_key($config, ['scopes' => true, 'extra_scopes' => true]), ...array_filter(SocialProviderFields::scopeConfig($template, $scopes), static fn (array $list): bool => $list !== [])];
                $changed[] = 'scopes';
            }
        }

        if ($changed !== []) {
            $connection->config_encrypted = $this->secretBox->seal(json_encode($config, JSON_THROW_ON_ERROR), $connection->secretContext());
            $connection->save();

            // WHICH values changed, never the values: one of them is a secret.
            $this->audit->record(SignInAudit::SOCIAL_PROVIDER_UPDATED, $context->actor(), $connection->organization_id, 'connection', $connection->id, [
                'provider' => $connection->provider,
                'changed' => $changed,
            ]);
        }

        return ActionResult::item($connection, SocialProviderFields::present($connection));
    }
}
