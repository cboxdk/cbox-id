<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\AccessControl\AppManifestPuller;
use Throwable;

/**
 * Fetch an app's manifest from its `manifest_url` now and sync the roles and permissions
 * it declares, rather than wait for the scheduled sweep. Roles it stopped declaring are
 * tombstoned, not deleted, and listed in the answer.
 *
 * A fetch or parse that fails — the URL unreachable, refused as a private address, not a
 * manifest — is refused with what went wrong (`manifest_sync_failed`), and changes nothing:
 * the sync runs inside the action's transaction.
 */
#[AsAction(
    name: 'apps.manifest.sync',
    summary: 'Fetch an app\'s published manifest now and sync the roles and permissions it declares.',
    scope: 'apps:write',
    danger: Danger::Write,
    tag: 'Apps',
    rest: ['POST', '/apps/{id}/manifest/sync'],
    consoleRoutes: ['clients.sync', 'environment.clients.sync'],
    consoleGate: ConsoleGate::Administer,
    schema: 'ManifestSync',
)]
final readonly class SyncAppManifest implements Action
{
    public function __construct(private AppManifestPuller $puller) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([AppFields::id()]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $client = AppFields::find($context, $context->string('id'));

        if ($client->manifest_url === null || $client->manifest_url === '') {
            throw ActionRefused::because('no_manifest_url', 'This app publishes no manifest URL. Set one first, or push its manifest instead.', 'manifest_url');
        }

        try {
            $result = $this->puller->pull($client);
        } catch (Throwable $failed) {
            throw ActionRefused::because('manifest_sync_failed', $failed->getMessage(), 'manifest_url');
        }

        if ($result === null) {
            throw ActionRefused::because('no_manifest_url', 'This app publishes no manifest URL. Set one first, or push its manifest instead.', 'manifest_url');
        }

        return ActionResult::item($result, [
            'unchanged' => $result->unchanged,
            'roles_declared' => $result->rolesDeclared,
            'permissions_declared' => $result->permissionsDeclared,
            'orphaned_role_keys' => $result->orphanedRoleKeys,
            'orphaned_permission_keys' => $result->orphanedPermissionKeys,
        ]);
    }
}
