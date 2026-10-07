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

/**
 * Rename an app and change where it may return people to — its details, as the console's
 * overview edits them.
 *
 * Built from the app's whole settings with only what was sent replaced — never a
 * hand-built blueprint, which would clear every setting this does not carry (the scopes,
 * the logout endpoint, the key prefix, the token lifetime). The registry records
 * `app.updated` only when something actually changed, so resending what is there is not an
 * edit on the trail.
 *
 * NOT HERE: the kind, the grants and the client type, which decide how the app
 * authenticates — changing one under a running integration is a different app — and the
 * scopes and settings, which have endpoints of their own so an edit to one never writes
 * back a stale copy of another.
 */
#[AsAction(
    name: 'apps.update',
    summary: 'Rename an app and replace its redirect URIs or sign-out redirect URIs. Fields left out are unchanged.',
    scope: 'apps:write',
    danger: Danger::Write,
    schema: 'App',
    tag: 'Apps',
    rest: ['PATCH', '/apps/{id}'],
    consoleRoutes: ['clients.update', 'environment.clients.update'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class UpdateApp implements Action
{
    public function __construct(private ClientRegistry $clients) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            AppFields::id(),
            Field::string('name')->min(1)->max(190),
            AppFields::uris('redirect_uris')->describe('The COMPLETE list afterwards.'),
            AppFields::uris('post_logout_redirect_uris')->describe('The COMPLETE list afterwards.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $client = AppFields::find($context, $context->string('id'));
        $settings = $this->clients->blueprint($client);

        if ($context->has('name')) {
            $settings = $settings->withName(trim($context->string('name')));
        }

        if ($context->has('redirect_uris')) {
            $settings = $settings->withRedirectUris(self::strings($context->array('redirect_uris')));
        }

        if ($context->has('post_logout_redirect_uris')) {
            $settings = $settings->withPostLogoutRedirectUris(self::strings($context->array('post_logout_redirect_uris')));
        }

        try {
            $updated = $this->clients->update($client, $settings, $context->actor());
        } catch (InvalidClientMetadata $refused) {
            throw AppFields::invalid($refused, 'redirect_uris');
        }

        return ActionResult::item($updated, AppResource::from($updated));
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && trim($value) !== ''));
    }
}
