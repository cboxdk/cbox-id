<?php

declare(strict_types=1);

namespace App\Actions\Directories;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionMessage;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseAudit;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\Kernel\Crypto\SecretPrefix;

/**
 * Re-key a SCIM directory: a new bearer token, shown once. The token the customer's
 * identity provider holds stops working the moment this commits, until somebody pastes
 * the new one in.
 *
 * Critical, and the token is in `redact` — it is a live inbound credential that
 * provisions an organization's people. Only its hash is stored; the trail records that a
 * token was issued, never the token. A pull directory authenticates OUTWARD to its
 * provider and has no inbound token, so it is refused.
 */
#[AsAction(
    name: 'directories.token.rotate',
    summary: 'Issue a new bearer token for a SCIM directory, returned once. The old token stops working immediately.',
    scope: 'directory_sync:write',
    danger: Danger::Critical,
    schema: 'Directory',
    tag: 'Directory Sync',
    rest: ['POST', '/directories/{id}/rotate'],
    consoleRoutes: ['directories.rotate', 'environment.directories.rotate'],
    consoleGate: ConsoleGate::Administer,
    redact: ['bearer_token'],
)]
final readonly class RotateDirectoryToken implements Action
{
    public function __construct(private EnterpriseAudit $audit) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The directory\'s id.'),
            EnterpriseReach::narrowField(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $directory = DirectoryFields::changeable($context);

        if ($directory->provider->isPull()) {
            throw ActionRefused::because('not_scim', new ActionMessage('console.a_pull_directory_authenticates_to_the_provider_and_has_7816b2af'));
        }

        // `cbid_scim_` since laravel-id 2.5. A `scim_` token minted before keeps working:
        // the directory is found by the hash of the whole token, never by its prefix.
        $token = SecretPrefix::ScimToken->mint();
        $directory->bearer_token_hash = hash('sha256', $token);
        $directory->save();

        $this->audit->record(EnterpriseAudit::DIRECTORY_TOKEN_ROTATED, $context->actor(), $directory->organization_id, 'directory', $directory->id, [
            'name' => $directory->name,
        ]);

        return ActionResult::item($token, DirectoryFields::present($directory, $token));
    }
}
