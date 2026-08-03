<?php

declare(strict_types=1);

namespace App\Platform;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * "Confirm your address before you can create anything."
 *
 * A social sign-in creates the account immediately — that is the point of it — but the
 * address on that account is only a claim the provider passed along, and we have decided
 * not to treat a provider's word as verification. Discord carries whatever someone
 * typed; GitHub will hand over an address the person never proved either.
 *
 * So the account exists and can be used, and the things that would OUTLIVE a mistake are
 * held until we have confirmed the address ourselves. Reading is free. Creating an
 * organization, an app, a key or a webhook is not, because each of those is a durable
 * object other people will trust — and an unverified address is one someone else may
 * actually own.
 *
 * Deliberately not a middleware. Creation on this plane happens inside Livewire
 * component methods, which all arrive over one endpoint, so a middleware could only
 * choose between holding every read too or catching nothing. The gate goes where the
 * write is, and a source-scan test (VerifiedEmailGateTest) is what stops a new write
 * from quietly skipping it — named in prose, not as a {@see}, because that would import
 * a test class into production code.
 */
class VerifiedEmailGate
{
    public function __construct(private readonly CurrentUser $current) {}

    public function verified(): bool
    {
        return $this->current->emailVerified();
    }

    /**
     * Refuse the write unless this platform has confirmed the address.
     *
     * 403 with a sentence that says what to do. An authorization failure that only says
     * "forbidden" reads as a bug in the product to the one person who can fix it in
     * seconds by opening their inbox.
     *
     * @throws AuthorizationException
     */
    public function require(string $what = 'create this'): void
    {
        if ($this->verified()) {
            return;
        }

        throw new AuthorizationException(
            'Confirm your email address before you '.$what.'. Check your inbox for the link we sent, or send a new one from your account page.'
        );
    }
}
