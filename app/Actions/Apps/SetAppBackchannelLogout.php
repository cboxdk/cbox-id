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
use Cbox\Id\OAuthServer\Support\BackchannelLogoutUri;

/**
 * Where this app is told that somebody signed out (OIDC Back-Channel Logout 1.0) — or,
 * with null, that it is not told at all.
 *
 * Validated exactly as at registration ({@see BackchannelLogoutUri}): HTTPS, or HTTP on
 * localhost only, because this server POSTs a signed logout token there and a URI it would
 * refuse to call is a setting that silently does nothing. `session_required` makes every
 * logout token carry `sid`, and means nothing without a URI.
 */
#[AsAction(
    name: 'apps.settings.backchannel_logout',
    summary: 'Set or clear the URI an app is sent a logout token at when somebody signs out (OIDC Back-Channel Logout).',
    scope: 'apps:write',
    danger: Danger::Write,
    schema: 'App',
    tag: 'Apps',
    rest: ['PUT', '/apps/{id}/settings/backchannel-logout'],
    consoleRoutes: ['clients.settings.logout', 'environment.clients.settings.logout'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class SetAppBackchannelLogout implements Action
{
    public function __construct(private ClientRegistry $clients) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            AppFields::id(),
            Field::string('uri')->nullable()->max(2000)->format('uri')->describe('HTTPS (HTTP on localhost only). Null or left out: the app is not told.'),
            Field::boolean('session_required')->describe('Every logout token carries `sid`. Default false.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $client = AppFields::find($context, $context->string('id'));
        $uri = $context->nullableString('uri');
        $uri = $uri === null ? null : (trim($uri) ?: null);

        try {
            $updated = $this->clients->update(
                $client,
                $this->clients->blueprint($client)->withBackchannelLogout($uri, $context->boolean('session_required')),
                $context->actor(),
            );
        } catch (InvalidClientMetadata $refused) {
            throw AppFields::invalid($refused, 'uri');
        }

        return ActionResult::item($updated, AppResource::from($updated));
    }
}
