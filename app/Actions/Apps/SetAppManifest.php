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
use Cbox\Id\OAuthServer\Exceptions\InvalidClientMetadata;

/**
 * Set — or, with null, clear — where an app publishes its roles-and-permissions manifest
 * (the PULL transport), which the scheduled sweep and `apps.manifest.sync` fetch.
 *
 * Through the registry like every other setting, so where an app's roles come from
 * changing is on the trail (`app.updated`) too — it decides what its tokens carry. Saving
 * does not fetch: `apps.manifest.sync` does, so a manifest that cannot be read yet never
 * stops the URL being saved, and a failed fetch never undoes a save that succeeded.
 */
#[AsAction(
    name: 'apps.manifest.set',
    summary: 'Set or clear the URL an app publishes its roles-and-permissions manifest at. Does not fetch it; apps.manifest.sync does.',
    scope: 'apps:write',
    danger: Danger::Write,
    schema: 'App',
    tag: 'Applications',
    rest: ['PUT', '/apps/{id}/manifest'],
    consoleRoutes: ['clients.manifest', 'environment.clients.manifest'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class SetAppManifest implements Action
{
    public function __construct(private ClientRegistry $clients) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            AppFields::id(),
            Field::string('manifest_url')->nullable()->max(500)->format('uri')->describe('An absolute URL; null or left out clears it.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $client = AppFields::find($context, $context->string('id'));
        $url = $context->nullableString('manifest_url');
        $url = $url === null ? null : (trim($url) ?: null);

        if ($url !== null && filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw ActionRefused::because('invalid_client_metadata', 'manifest_url must be an absolute URL or null.', 'manifest_url');
        }

        try {
            $updated = $this->clients->update($client, $this->clients->blueprint($client)->withManifestUrl($url), $context->actor());
        } catch (InvalidClientMetadata $refused) {
            throw AppFields::invalid($refused, 'manifest_url');
        }

        return ActionResult::item($updated, AppResource::from($updated));
    }
}
