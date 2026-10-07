<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;

/**
 * The credentials in one vault — an organization's, or the environment's own — by name
 * and provider. Never a value.
 */
#[AsAction(
    name: 'token_vault.secrets.list',
    summary: 'List the downstream credentials stored in an organization\'s token vault (or the environment\'s own): names, providers, status. Never a value.',
    scope: 'token_vault:read',
    danger: Danger::Read,
    schema: 'TokenVaultSecret',
    tag: 'Token vault',
    rest: ['GET', '/token-vault/secrets'],
)]
final class ListVaultSecrets implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of([
            VaultFields::ownerField(),
            ...self::pageFields(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        return $this->page(
            VaultFields::secrets(VaultFields::owner($context)),
            $context,
            static fn ($secret): array => VaultFields::present($secret),
        );
    }
}
