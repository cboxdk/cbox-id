<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * Why a step-up prompt appeared, in the words of the action that raised it.
 *
 * "This is a protected action" is copy a person learns to type a password past. The
 * prompts this platform raises are not interchangeable — one is about to issue a client
 * secret that signs in as somebody's production app, another about to revoke the key that
 * runs their provisioning — and the screen that asks should say which, or the
 * confirmation is a reflex rather than a decision.
 *
 * BOUND TO A DESTINATION, which is the whole reason this is a value and not a loose
 * string. The sentence is recorded next to the intended URL it belongs to and is shown
 * only while that URL is still the pending one. A step-up abandoned halfway — the tab is
 * closed, the administrator goes elsewhere — would otherwise leave its sentence behind to
 * caption an unrelated prompt raised later by one of the route middlewares, which record
 * an intent and no reason at all.
 */
final readonly class StepUpReason
{
    /**
     * WHAT A ROUTE-GATED STEP-UP SAYS, by route name.
     *
     * The action-level gate always passes its own sentence. The route middlewares had none
     * to pass, so every prompt they raised read "This is a protected action" — including the
     * one in front of adding a passkey, which is the step-up people meet most. A route
     * named here says what is waiting instead; one that is not says the generic sentence,
     * which is still true.
     *
     * @var array<string, string>
     */
    public const array ROUTES = [
        'passkeys.register.options' => 'Adding a passkey gives you a new way to sign in.',
        'passkeys.register' => 'Adding a passkey gives you a new way to sign in.',
        'account.sign-in-methods.connect' => 'Connecting an account gives you a new way to sign in.',
        'social.connect' => 'Connecting an account gives you a new way to sign in.',
        'social.connect.callback' => 'Connecting an account gives you a new way to sign in.',
        'account.password.update' => 'Changing your password changes how you sign in.',
        'account.mfa.enrol' => 'Setting up an authenticator app changes how you sign in.',
        'account.mfa.confirm' => 'Setting up an authenticator app changes how you sign in.',
        'account.mfa.recovery-codes' => 'New recovery codes replace the ones you have, and are shown once.',
        'account.mfa.sms.enrol' => 'Adding a phone number changes how you sign in.',
        'account.mfa.sms.confirm' => 'Adding a phone number changes how you sign in.',
        'account.mfa.sms.destroy' => 'Removing your phone number changes how you sign in.',
        'account.passkeys.destroy' => 'Removing a passkey changes how you sign in.',
        'account.social.destroy' => 'Disconnecting an account changes how you sign in.',
        'account.sign-in-methods.destroy' => 'Disconnecting an account changes how you sign in.',
        'account.sessions.revoke-others' => 'Signing out everywhere else ends every other session at once.',
        'settings.organization.destroy' => 'Closing the organization removes it for everyone in it.',
    ];

    public function __construct(
        public string $sentence,
        public string $intended,
    ) {}

    /**
     * Record why the prompt `$key` is about to raise exists.
     *
     * `$key` is the session prefix the step-up already owns — `sudo` for the console,
     * `environment.sudo` for the plane above it — so the reason and the intent it belongs
     * to cannot be spelled apart from one another.
     */
    public static function record(string $key, string $sentence, string $intended): void
    {
        session()->put($key.'.reason', new self($sentence, $intended));
    }

    /** The sentence for the prompt now on screen, or null when none was recorded for it. */
    public static function pending(string $key): ?string
    {
        $reason = session()->get($key.'.reason');
        $intended = session()->get($key.'.intended');

        return $reason instanceof self && is_string($intended) && $reason->intended === $intended
            ? $reason->sentence
            : null;
    }

    /** The sentence a route-gated step-up shows for `$route`, or null for the generic one. */
    public static function forRoute(?string $route): ?string
    {
        return $route === null ? null : (self::ROUTES[$route] ?? null);
    }

    /** Spent alongside the intent it belongs to, once the step-up is through. */
    public static function forget(string $key): void
    {
        session()->forget($key.'.reason');
    }
}
