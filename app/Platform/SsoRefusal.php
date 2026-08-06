<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Enums\RefusedFactor;

/**
 * A mandate refusal, carried from a door that cannot explain it to the screen that can.
 *
 * The password doors are Livewire components: they refuse and render the explanation in
 * the same request, so the refusal never has to travel. Every other door is a controller
 * or a token flow — a redeemed magic link, a WebAuthn ceremony answering JSON, a social
 * callback, an accepted invitation — and each one ends in a redirect or a `window.location`.
 * Their only way to say anything is the screen they send the browser to.
 *
 * So they hand the sign-in screen exactly two things: WHO was refused, and WHAT they had
 * just proved. The screen resolves the organization itself through {@see SsoMandates},
 * which is the one place that lookup lives — a door that resolved it and passed a name
 * through the session would be a second copy of that rule, and the copy is always the one
 * that stops naming the right connection.
 *
 * The subject id is safe to carry: the session is the browser's own, server-side and
 * signed, and it is only ever written after a factor has been PROVEN. It is taken once —
 * a refusal that survived into the next visit would greet somebody with a dead end they
 * had already left.
 *
 * Static, like {@see SsoStart}, because it is a place in the session rather than a
 * collaborator: every door that writes it and the one screen that reads it need to agree
 * on that place, and an injected object would let a second key quietly appear.
 */
final class SsoRefusal
{
    private const KEY = 'cbox.sso_refusal';

    private function __construct(
        public readonly string $subjectId,
        public readonly RefusedFactor $factor,
    ) {}

    /** Hold the refusal for the sign-in screen the door is about to redirect to. */
    public static function hold(string $subjectId, RefusedFactor $factor): void
    {
        session()->put(self::KEY, ['subject' => $subjectId, 'factor' => $factor->value]);
    }

    /**
     * Take the held refusal, or null when the visitor simply arrived at the sign-in page.
     *
     * Scalars in, value object out. The session is a serialization edge and an enum stored
     * as an object is a deploy away from being unreadable, so what goes in is a string and
     * what comes back is re-narrowed — a factor that no longer exists yields null rather
     * than a fatal, and the screen falls back to being an ordinary sign-in page.
     */
    public static function take(): ?self
    {
        $held = session()->pull(self::KEY);

        if (! is_array($held) || ! is_string($held['subject'] ?? null) || ! is_string($held['factor'] ?? null)) {
            return null;
        }

        $factor = RefusedFactor::tryFrom($held['factor']);

        return $factor === null || $held['subject'] === '' ? null : new self($held['subject'], $factor);
    }
}
