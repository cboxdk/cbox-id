<?php

declare(strict_types=1);

namespace App\Actions\Directories;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseAudit;
use App\Platform\Enterprise\EnterpriseReach;

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
    tag: 'Directory sync',
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
            throw ActionRefused::because('not_scim', 'A pull directory authenticates to the provider and has no inbound token to rotate.');
        }

        $token = 'scim_'.bin2hex(random_bytes(32));
        $directory->bearer_token_hash = hash('sha256', $token);
        $directory->save();

        $this->audit->record(EnterpriseAudit::DIRECTORY_TOKEN_ROTATED, $context->actor(), $directory->organization_id, 'directory', $directory->id, [
            'name' => $directory->name,
        ]);

        return ActionResult::item($token, DirectoryFields::present($directory, $token));
    }
}
