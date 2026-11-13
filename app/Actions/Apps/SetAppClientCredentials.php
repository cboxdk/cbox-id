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
use App\Platform\Actions\RatedByInput;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Enums\GrantType;
use Cbox\Id\OAuthServer\Exceptions\InvalidClientMetadata;

/** Enable unattended authentication without replacing an existing confidential app. */
#[AsAction(
    name: 'apps.settings.client_credentials',
    summary: 'Turn client-credentials authentication on or off for a confidential app; other grants and scopes stay unchanged.',
    scope: 'apps:write',
    danger: Danger::Write,
    schema: 'App',
    tag: 'Applications',
    rest: ['PUT', '/apps/{id}/settings/client-credentials'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class SetAppClientCredentials implements Action, RatedByInput
{
    public function __construct(private ClientRegistry $clients) {}

    /**
     * Turning the machine grant ON is Critical ({@see RatedByInput}): the app's secret
     * becomes a way to act as the app with no person present. Turning it off is a Write.
     */
    public function dangerFor(ActionContext $context): Danger
    {
        return $context->boolean('enabled') ? Danger::Critical : Danger::Write;
    }

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
            throw ActionRefused::because('public_client', 'Only an app that holds a secret or its own keys can authenticate as a service. A public app cannot prove who is asking.', 'enabled');
        }

        $grants = array_values(array_filter(
            $client->grant_types,
            static fn (string $grant): bool => $grant !== GrantType::ClientCredentials->value,
        ));

        if ($context->boolean('enabled')) {
            AppFields::refuseCredentialForReserved($context, $client, 'enabled');
            $grants[] = GrantType::ClientCredentials->value;
        }

        try {
            $updated = $this->clients->update($client, $this->clients->blueprint($client)->withGrantTypes($grants), $context->actor());
        } catch (InvalidClientMetadata $refused) {
            throw AppFields::invalid($refused, 'enabled');
        }

        return ActionResult::item($updated, AppResource::from($updated));
    }
}
