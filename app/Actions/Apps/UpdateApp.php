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
use App\Platform\Actions\RatedByInput;
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
 *
 * CRITICAL WHEN IT ADDS A DESTINATION ({@see RatedByInput}): a redirect URI the app did not
 * have is a new place its authorization codes are delivered — what `apps.create` is — and
 * a new sign-out redirect is a new place people are sent. Renaming, or removing a URI,
 * stays a Write; resending the list as it is adds nothing.
 */
#[AsAction(
    name: 'apps.update',
    summary: 'Rename an app and replace its redirect URIs or sign-out redirect URIs. Fields left out are unchanged.',
    scope: 'apps:write',
    danger: Danger::Write,
    schema: 'App',
    tag: 'Applications',
    rest: ['PATCH', '/apps/{id}'],
    consoleRoutes: ['clients.update', 'environment.clients.update'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class UpdateApp implements Action, RatedByInput
{
    public function __construct(private ClientRegistry $clients) {}

    public function dangerFor(ActionContext $context): Danger
    {
        if (! $context->has('redirect_uris') && ! $context->has('post_logout_redirect_uris')) {
            return Danger::Write;
        }

        $client = AppFields::find($context, $context->string('id'));

        foreach (['redirect_uris', 'post_logout_redirect_uris'] as $field) {
            if (! $context->has($field)) {
                continue;
            }

            $stored = $client->getAttribute($field);
            $current = is_array($stored) ? self::strings($stored) : [];

            if (array_diff(self::strings($context->array($field)), $current) !== []) {
                return Danger::Critical;
            }
        }

        return Danger::Write;
    }

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
            $redirectUris = self::strings($context->array('redirect_uris'));

            // A NEW redirect URI on an app holding a console-reserved scope sends its codes,
            // and so that authority, somewhere new. Removing one, or keeping them, is fine.
            if (array_diff($redirectUris, $client->redirect_uris) !== []) {
                AppFields::refuseCredentialForReserved($context, $client, 'redirect_uris');
            }

            $settings = $settings->withRedirectUris($redirectUris);
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
