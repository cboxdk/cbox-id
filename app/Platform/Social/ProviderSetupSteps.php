<?php

declare(strict_types=1);

namespace App\Platform\Social;

use App\Platform\AccountLinking\EntraVerifiedEmail;
use Cbox\Id\Federation\ProviderCatalog;
use Cbox\Id\Federation\ValueObjects\ProviderTemplate;

/**
 * THE SETUP STEPS THE CONSOLE SHOWS FOR A CATALOGUE PROVIDER — the framework's, plus what
 * this application needs from the provider that the framework does not.
 *
 * {@see ProviderCatalog} owns the steps for registering an app with each provider, and
 * they are right for signing people IN. This application also LINKS a provider identity
 * to an account that already uses the same address, and offers that only when the
 * provider says the address is verified. Microsoft Entra ID says so only through an
 * optional claim the app registration has to ask for ({@see EntraVerifiedEmail}); a
 * registration made from the framework's steps alone works for sign-in and then refuses
 * every link, with nothing on the setup screen saying why. So the step is added here,
 * where the reason for it lives.
 *
 * Added once: should the framework's own steps come to mention the claim, they are shown
 * as they are.
 */
final class ProviderSetupSteps
{
    /** @return list<string> */
    public static function for(ProviderTemplate $template): array
    {
        $steps = $template->setupSteps;

        if ($template->key === 'microsoft' && ! self::mentions($steps, EntraVerifiedEmail::CLAIM)) {
            $steps[] = 'Under Token configuration, select Add optional claim, choose ID, and tick email and xms_edov (accept the Microsoft Graph permission it asks for). Entra never sends email_verified; xms_edov is how it says an address is in a domain your tenant has verified, and without it a person whose address already has an account here cannot link their Microsoft sign-in to it.';
        }

        return $steps;
    }

    /** @param  list<string>  $steps */
    private static function mentions(array $steps, string $needle): bool
    {
        foreach ($steps as $step) {
            if (str_contains($step, $needle)) {
                return true;
            }
        }

        return false;
    }
}
