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
 * Let an app (an OAuth client) lease a stored credential: from now on, a `vault.lease`
 * token of that client can open it and present it downstream.
 *
 * Critical — this is handing out a credential, once removed: the vault's deny-by-default
 * is exactly this list. Re-granting a revoked pair reactivates it. A revoked secret can be
 * leased by nobody, so granting on one is refused. The vault records `vault.grant.created`.
 */
#[AsAction(
    name: 'token_vault.grants.create',
    summary: 'Grant an app (by OAuth client id) the right to lease a stored credential.',
    scope: 'token_vault:write',
    danger: Danger::Critical,
    schema: 'TokenVaultSecret',
    tag: 'Token vault',
    rest: ['POST', '/token-vault/secrets/{id}/grants'],
    consoleRoutes: ['vault.grants.store', 'environment.vault.grants.store', 'environment.organizations.vault.grants.store'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class GrantVaultAccess implements Action
{
    public function __construct(private SecretVault $vault) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The secret\'s id.'),
            VaultFields::ownerField(),
            Field::string('client_id')->required()->max(190)->describe('The OAuth client id of the app that may lease it.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $secret = VaultFields::secret($context);

        VaultFields::assertLive($secret, 'client_id');

        $this->vault->grant($secret->id, trim($context->string('client_id')), VaultFields::owner($context));

        return ActionResult::item($secret, VaultFields::present($secret, withGrants: true));
    }
}
