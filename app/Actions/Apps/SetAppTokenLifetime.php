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
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Exceptions\InvalidClientMetadata;
use Cbox\Id\OAuthServer\Support\AccessTokenLifetime;

/**
 * How long this app's access tokens live, in seconds — or null for the install's default.
 *
 * A token a resource server validates offline can only be revoked by expiry, so its
 * lifetime IS its revocation window; an app whose tokens reach something sensitive is
 * given a shorter one here. Bounded by the registry ({@see AccessTokenLifetime}): at least a
 * minute, below which clock skew expires a token before it is used, and at most the
 * install's ceiling. One setting, from the app's own blueprint with only it replaced, so
 * this never writes back a value another setting changed since.
 */
#[AsAction(
    name: 'apps.settings.token_lifetime',
    summary: 'Set how long an app\'s access tokens live, in seconds, or null for the install\'s default.',
    scope: 'apps:write',
    danger: Danger::Write,
    schema: 'App',
    tag: 'Apps',
    rest: ['PUT', '/apps/{id}/settings/token-lifetime'],
    consoleRoutes: ['clients.settings.lifetime', 'environment.clients.settings.lifetime'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class SetAppTokenLifetime implements Action
{
    public function __construct(private ClientRegistry $clients) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            AppFields::id(),
            Field::integer('access_token_ttl')->nullable()->describe('Seconds, from 60 to the install\'s ceiling. Null or left out: the install\'s default.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $client = AppFields::find($context, $context->string('id'));
        $seconds = $context->nullableString('access_token_ttl');

        try {
            $updated = $this->clients->update(
                $client,
                $this->clients->blueprint($client)->withAccessTokenTtl($seconds === null ? null : (int) $seconds),
                $context->actor(),
            );
        } catch (InvalidClientMetadata $refused) {
            throw AppFields::invalid($refused, 'access_token_ttl');
        }

        return ActionResult::item($updated, AppResource::from($updated));
    }
}
