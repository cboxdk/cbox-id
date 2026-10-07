<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Http\Resources\Environment\AppResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Enums\GrantType;
use Cbox\Id\OAuthServer\Exceptions\InvalidClientMetadata;

/**
 * Whether this app may exchange one token for another (RFC 8693 token exchange) — the
 * grant an API uses to call the next one on a person's behalf.
 *
 * Only an app that can prove who it is may: the token endpoint requires client
 * authentication for the grant, so turning it on for a public app would offer a grant
 * refused on every call (`public_client`). Every other grant the app holds is left as it
 * is.
 */
#[AsAction(
    name: 'apps.settings.token_exchange',
    summary: 'Turn token exchange (RFC 8693) on or off for a confidential app.',
    scope: 'apps:write',
    danger: Danger::Write,
    schema: 'App',
    tag: 'Apps',
    rest: ['PUT', '/apps/{id}/settings/token-exchange'],
    consoleRoutes: ['clients.settings.exchange', 'environment.clients.settings.exchange'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class SetAppTokenExchange implements Action
{
    public function __construct(private ClientRegistry $clients) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            AppFields::id(),
            Field::boolean('enabled')->required(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $client = AppFields::find($context, $context->string('id'));

        if ($client->type !== ClientType::Confidential) {
            throw ActionRefused::because('public_client', 'Only an app that holds a secret or its own keys can exchange tokens. A public app cannot prove who is asking.', 'enabled');
        }

        $grants = array_values(array_filter(
            $client->grant_types,
            static fn (string $grant): bool => $grant !== GrantType::TokenExchange->value,
        ));

        if ($context->boolean('enabled')) {
            $grants[] = GrantType::TokenExchange->value;
        }

        try {
            $updated = $this->clients->update($client, $this->clients->blueprint($client)->withGrantTypes($grants), $context->actor());
        } catch (InvalidClientMetadata $refused) {
            throw AppFields::invalid($refused, 'enabled');
        }

        return ActionResult::item($updated, AppResource::from($updated));
    }
}
