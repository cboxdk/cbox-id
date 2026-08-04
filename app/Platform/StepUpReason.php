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
    public function __construct(
        public string $sentence,
        public string $intended,
    ) {}

    /**
     * Record why the prompt `$key` is about to raise exists.
     *
     * `$key` is the session prefix the plane's step-up already owns — `sudo`,
     * `environment.sudo`, `workspace.sudo` — so the reason and the intent it belongs to
     * cannot be spelled apart from one another.
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

    /** Spent alongside the intent it belongs to, once the step-up is through. */
    public static function forget(string $key): void
    {
        session()->forget($key.'.reason');
    }
}
