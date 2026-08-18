<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\SecretLine;
use App\Platform\Install\Contracts\SetupTokens;
use Illuminate\Console\Command;

/**
 * Print the first-run setup token, for an operator claiming an unclaimed deployment.
 *
 * Exists because the token stopped going into the application log. It is the whole of
 * the authority to claim a platform that has no operator yet, and a log shipped to a
 * central aggregator hands that authority to everyone who can read it — so the copy that
 * travelled is gone and this is the copy that does not.
 *
 * It reads what is already there and never mints: `issue()` would create a token on a
 * platform that is already claimed, which is a credential nobody asked for.
 */
class SetupTokenCommand extends Command
{
    protected $signature = 'cbox-id:setup-token';

    protected $description = 'Print this deployment’s first-run setup token, if it has not been claimed yet';

    public function handle(SetupTokens $tokens): int
    {
        $token = $tokens->current();

        if ($token === null) {
            $this->components->info('This deployment has no setup token — it has already been claimed.');

            return self::SUCCESS;
        }

        $this->components->warn('Anyone holding this token can claim this deployment. It stops working the moment it is used.');

        // Through SecretLine, not line(): Symfony's formatter reads `<…>` as markup and
        // eats it, and a hex token that lost a character is a token that silently fails.
        SecretLine::write($this->output->getOutput(), $token);

        return self::SUCCESS;
    }
}
