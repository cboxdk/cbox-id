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
 * Replace a stored credential's sealed value. Every lease from now on hands out the new
 * one.
 *
 * Critical: it decides what every granted app presents to a third party in this
 * organization's name — swapped for a credential the caller controls, it redirects them.
 * The new value is input only and never returned. A revoked secret has no future lease to
 * serve, so rotating one is refused rather than performed as busywork that reads as a fix.
 * The vault records `vault.secret.rotated`.
 */
#[AsAction(
    name: 'token_vault.secrets.rotate',
    summary: 'Replace a stored credential\'s value. Every later lease hands out the new one. The value is never returned.',
    scope: 'token_vault:write',
    danger: Danger::Critical,
    schema: 'TokenVaultSecret',
    tag: 'Token vault',
    rest: ['POST', '/token-vault/secrets/{id}/rotate'],
    consoleRoutes: ['vault.rotate', 'environment.vault.rotate'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class RotateVaultSecret implements Action
{
    public function __construct(private SecretVault $vault) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The secret\'s id.'),
            VaultFields::ownerField(),
            Field::string('secret')->required()->max(20000)->describe('The new value. Write-only: sealed, never returned.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $secret = VaultFields::secret($context);

        VaultFields::assertLive($secret, 'secret');

        $rotated = $this->vault->rotate($secret->id, $context->string('secret'), VaultFields::owner($context));

        return ActionResult::item($rotated, VaultFields::present($rotated, withGrants: true));
    }
}
