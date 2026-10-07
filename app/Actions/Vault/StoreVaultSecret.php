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
 * Seal a downstream credential into a vault — an API key for a third party that an
 * organization's apps and agents will lease. No app may lease it until one is granted
 * ({@see GrantVaultAccess}).
 *
 * The value is input only: sealed before this request ends and never returned, by this
 * or any action. An idempotent replay stores nothing again and returns the same answer;
 * only a hash of the request is kept to recognise it. The vault records
 * `vault.secret.stored`.
 */
#[AsAction(
    name: 'token_vault.secrets.create',
    summary: 'Store a downstream credential (a third-party API key) in an organization\'s token vault, or the environment\'s own. The value is sealed and never returned.',
    scope: 'token_vault:write',
    danger: Danger::Write,
    schema: 'TokenVaultSecret',
    tag: 'Token vault',
    rest: ['POST', '/token-vault/secrets'],
    status: 201,
    consoleRoutes: ['vault.store', 'environment.vault.store'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class StoreVaultSecret implements Action
{
    public function __construct(private SecretVault $vault) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            VaultFields::ownerField(),
            Field::string('name')->required()->max(190)->describe('What it is: "Stripe live key".'),
            Field::string('provider')->required()->max(190)->describe('Whose credential it is: "stripe", "openai".'),
            Field::string('secret')->required()->max(20000)->describe('The credential. Write-only: sealed, never returned.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $secret = $this->vault->store(
            trim($context->string('name')),
            trim($context->string('provider')),
            $context->string('secret'),
            VaultFields::owner($context),
        );

        $secret->refresh();

        return ActionResult::item($secret, VaultFields::present($secret, withGrants: true));
    }
}
