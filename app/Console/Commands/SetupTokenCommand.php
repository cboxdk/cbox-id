<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\SecretLine;
use App\Platform\Install\Contracts\PlatformInstaller;
use App\Platform\Install\Contracts\SetupTokens;
use Illuminate\Console\Command;

/**
 * Print a first-run setup token, for an operator claiming an unclaimed deployment.
 *
 * Exists because the token stopped going into the application log. It is the whole of
 * the authority to claim a platform that has no operator yet, and a log shipped to a
 * central aggregator hands that authority to everyone who can read it — so the copy that
 * travelled is gone and this is the copy that does not.
 *
 * IT MINTS A FRESH TOKEN EVERY TIME. The token is kept only as a hash, in the database
 * every replica shares, so there is nothing to read back: printing one means minting one,
 * and the previous token stops working at once. That is also what makes it work from any
 * pod of a multi-replica deployment — the token printed here is accepted by whichever
 * instance answers the claim.
 *
 * It refuses to mint on a platform that is already claimed — a token there would be a
 * credential nobody asked for, for a door that no longer exists — and on a database that
 * has not been migrated, where there is nowhere to keep one.
 */
class SetupTokenCommand extends Command
{
    protected $signature = 'cbox-id:setup-token';

    protected $description = 'Mint and print a first-run setup token for this deployment, if it has not been claimed yet';

    public function handle(SetupTokens $tokens, PlatformInstaller $installer): int
    {
        if (! $installer->ready()) {
            // Checked FIRST: an un-migrated database also reads as empty, and the token
            // lives in a table that does not exist yet.
            $this->components->error('The database has not been migrated yet. Run `php artisan migrate --force`, then run this again.');

            return self::FAILURE;
        }

        if (! $installer->isEmpty()) {
            // Spend any leftover, so a claimed platform holds no live setup secret.
            $tokens->forget();

            $this->components->info('This deployment has already been claimed — there is no setup token to print.');

            return self::SUCCESS;
        }

        $token = $tokens->rotate();
        $expires = $tokens->expiresAt();

        $this->components->warn(
            'Anyone holding this token can claim this deployment. Any token printed before it no longer works, '
            .'and this one stops working the moment it is used'
            .($expires === null ? '.' : ' or at '.$expires->toIso8601String().', whichever comes first.'),
        );

        // Through SecretLine, not line(): Symfony's formatter reads `<…>` as markup and
        // eats it, and a hex token that lost a character is a token that silently fails.
        SecretLine::write($this->output->getOutput(), $token);

        return self::SUCCESS;
    }
}
