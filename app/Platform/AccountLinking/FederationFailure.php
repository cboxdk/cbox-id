<?php

declare(strict_types=1);

namespace App\Platform\AccountLinking;

use App\Platform\FederatedLanding;

/**
 * WHY A FEDERATED SIGN-IN DID NOT SIGN ANYBODY IN — every reason there is, each with the
 * sentence the person is shown.
 *
 * This exists because the reasons used to be English strings typed at each callback and
 * handed to {@see FederatedLanding::failed()}, which put them in the validation bag under
 * `email`. The sign-in page read its errors from its own form, which is empty on a fresh
 * load, so every one of those sentences was dropped: somebody who had just authenticated
 * at Google — successfully — came back to a blank sign-in form with no idea why. The
 * production report that found it was an owner whose Google address belonged to his own
 * password account, bounced to `/login` without a word.
 *
 * So the reason is a CASE, not a string. A case is translated in the six hosted languages
 * (`auth.federation.*`), it can be asserted on by name in a test, and a callback cannot
 * invent a seventh wording that nobody translated. What the page does with it is the
 * landing's business, and it does the same thing on every host and plane.
 *
 * `:provider` is the name the person clicked — "Google", or the organization's own name
 * for its identity provider — so every sentence says which door it is about.
 */
enum FederationFailure: string
{
    /** The state or nonce was missing, stale or replayed: a bookmarked callback, Back, a second tab. */
    case Expired = 'expired';

    /** The provider's answer did not verify — signature, issuer, audience, nonce. */
    case Rejected = 'rejected';

    /** The connection was switched off, removed, or never existed on this environment. */
    case Inactive = 'inactive';

    /** The provider came back with an `error` instead of a code — usually "Cancel" on its consent screen. */
    case Cancelled = 'cancelled';

    /** The provider could not be reached, or its configuration cannot start a sign-in. */
    case Unavailable = 'unavailable';

    /** A SAML response arrived without the parts a sign-in needs. */
    case Incomplete = 'incomplete';

    /**
     * The address belongs to an account here and the provider has NOT vouched for it, so
     * there is nothing to offer a link on. See {@see VouchedEmail}.
     */
    case Unverified = 'unverified';

    /** That provider identity is already attached to a different account. */
    case AlreadyLinked = 'already_linked';

    /** The account it resolves to has been deactivated. */
    case AccountDisabled = 'account_disabled';

    /** The held link expired, was already used, or failed its integrity check. */
    case LinkExpired = 'link_expired';

    /** Too many wrong answers on the link step: the hold is gone and the person starts over. */
    case LinkAttemptsExhausted = 'link_attempts_exhausted';

    /** Radar refused the attempt. Deliberately the generic sentence: naming the rule names what to change. */
    case Refused = 'refused';

    /** Connecting from My account, but the browser is no longer signed in as the person who started it. */
    case ConnectSessionChanged = 'connect_session_changed';

    /** The translation key — `auth.federation.<case>`. */
    public function key(): string
    {
        return 'auth.federation.'.$this->value;
    }

    /**
     * The sentence, in the request's language — or in `$locale`, which the console (an
     * English surface) asks for by name.
     *
     * @param  array<string, string>  $replace
     */
    public function message(array $replace = [], ?string $locale = null): string
    {
        $provider = __('auth.federation.this_provider', [], $locale);

        $message = __($this->key(), [
            // Every sentence names the provider; a caller that does not know which one it
            // is (a connection that resolved to nothing) still gets a grammatical line.
            'provider' => is_string($provider) ? $provider : '',
            ...array_filter($replace, static fn (string $value): bool => $value !== ''),
        ], $locale);

        return is_string($message) ? $message : $this->value;
    }
}
