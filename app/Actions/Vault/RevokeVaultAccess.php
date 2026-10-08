<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\TokenVault\Contracts\SecretVault;

/**
 * Take back an app's right to lease a stored credential. Leases already handed out run
 * to their expiry; no new one is. The vault records `vault.grant.revoked`.
 */
#[AsAction(
    name: 'token_vault.grants.delete',
    summary: 'Withdraw an app\'s right to lease a stored credential.',
    scope: 'token_vault:write',
    danger: Danger::Destructive,
    tag: 'Token vault',
    rest: ['DELETE', '/token-vault/secrets/{id}/grants/{client_id}'],
    status: 204,
    consoleRoutes: ['vault.grants.destroy', 'environment.vault.grants.destroy', 'environment.organizations.vault.grants.destroy'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class RevokeVaultAccess implements Action
{
    public function __construct(private SecretVault $vault) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The secret\'s id.'),
            Field::string('client_id')->inPath()->max(190)->describe('The OAuth client id whose grant is withdrawn.'),
            VaultFields::ownerField(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $secret = VaultFields::secret($context);

        $this->vault->revokeGrant($secret->id, $context->string('client_id'), VaultFields::owner($context));

        return ActionResult::none($secret);
    }
}
