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
 * Revoke a stored credential for good: no future lease can open it, whatever grants it
 * has. The vault records `vault.secret.revoked`.
 */
#[AsAction(
    name: 'token_vault.secrets.revoke',
    summary: 'Revoke a stored credential permanently. No app can lease it again.',
    scope: 'token_vault:write',
    danger: Danger::Destructive,
    schema: 'TokenVaultSecret',
    tag: 'Token vault',
    rest: ['POST', '/token-vault/secrets/{id}/revoke'],
    consoleRoutes: ['vault.revoke', 'environment.vault.revoke', 'environment.organizations.vault.revoke'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class RevokeVaultSecret implements Action
{
    public function __construct(private SecretVault $vault) {}

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

        $this->vault->revoke($secret->id, VaultFields::owner($context));

        $secret->refresh();

        return ActionResult::item($secret, VaultFields::present($secret));
    }
}
