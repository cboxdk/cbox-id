<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\ConsoleSignIn\ConsoleClient;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Register the console's own OAuth client (login-host mode), or bring it in line.
 *
 * Idempotent, so a deploy can run it every time: the first run creates the client and
 * prints its secret once; later runs only correct its redirect, sign-out and back-channel
 * logout addresses to the configured app host. `--rotate` mints a new secret (the old one
 * stops working at once) for when the stored one is lost or has to be replaced.
 */
final class ConsoleClientCommand extends Command
{
    protected $signature = 'cbox-id:console:client
        {--rotate : Mint a new client secret and print it. The previous one stops working.}
        {--secret-only : Print nothing but the secret, for a pipe into a secret store. Prints nothing when no secret was minted.}';

    protected $description = 'Provision the OAuth client the console signs in with at the login host (login-host mode)';

    public function handle(ConsoleClient $console): int
    {
        try {
            $result = $console->provision(rotate: $this->option('rotate') === true);
        } catch (RuntimeException $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        $secret = $result['secret'];

        if ($this->option('secret-only') === true) {
            if ($secret !== null) {
                $this->line($secret);
            }

            return self::SUCCESS;
        }

        $this->info($result['created']
            ? 'Registered the console client.'
            : 'The console client is registered; its addresses match the app host.');
        $this->line('  client_id:              '.$result['client']->client_id);
        $this->line('  redirect_uri:           '.$console->redirectUri());
        $this->line('  post_logout_redirect:   '.$console->postLogoutRedirectUri());
        $this->line('  backchannel_logout_uri: '.$console->backchannelLogoutUri());

        if ($secret !== null) {
            $this->newLine();
            $this->line('  Set this where the deployment keeps its secrets. It is shown once:');
            $this->line('  <options=bold>CBOX_ID_CONSOLE_CLIENT_SECRET='.$secret.'</>');
        } elseif ($console->secret() === null) {
            $this->newLine();
            $this->warn('CBOX_ID_CONSOLE_CLIENT_SECRET is not set. If the secret was lost, run this again with --rotate.');
        }

        return self::SUCCESS;
    }
}
