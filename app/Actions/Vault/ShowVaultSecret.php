<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;

/**
 * One stored credential and the apps that may lease it. Deny by default: an app not
 * listed in `grants` cannot.
 */
#[AsAction(
    name: 'token_vault.secrets.get',
    summary: 'Read one stored credential and the client ids granted to lease it. Never its value.',
    scope: 'token_vault:read',
    danger: Danger::Read,
    schema: 'TokenVaultSecret',
    tag: 'Token vault',
    rest: ['GET', '/token-vault/secrets/{id}'],
)]
final class ShowVaultSecret implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The secret\'s id.'),
            VaultFields::ownerField(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $secret = VaultFields::secret($context);

        return ActionResult::item($secret, VaultFields::present($secret, withGrants: true));
    }
}
