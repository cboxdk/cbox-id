<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;

/**
 * A federated identity held aside while its owner proves they also control the existing
 * account that shares its address.
 *
 * The flow exists because we refuse to merge accounts by email. When someone signs in
 * with GitHub and that address already belongs to an account, the safe answer is neither
 * "log them in" (the provider's word for an address is not proof) nor "refuse" (a
 * dead end for the legitimate owner). We hold the identity, ask them to sign in, and
 * then ask them, in words, whether to attach it.
 *
 * Two properties do the security work:
 *
 *  - `heldAt` bounds the window. An identity that sat in a session for a day is not
 *    evidence of anything, and a link is a permanent new way into an account.
 *  - `awaitingSubjectId` binds the decision to ONE account. It is written when someone
 *    authenticates and read when they confirm, so an identity held for account A can
 *    never be stapled onto account B by whoever signs in next.
 *
 * The email is carried for DISPLAY, not for comparison — see PlatformAuth::confirm().
 */
readonly class PendingLink
{
    /** Ten minutes: long enough to sign in and read a screen, short enough not to linger. */
    public const TTL_SECONDS = 600;

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $provider,
        public string $subject,
        public ?string $email,
        public ?string $name,
        public array $raw,
        public int $heldAt,
        public ?string $awaitingSubjectId = null,
    ) {}

    public static function hold(FederatedPrincipal $principal, int $now): self
    {
        return new self(
            provider: $principal->provider,
            subject: $principal->subject,
            email: $principal->email,
            name: $principal->name,
            raw: $principal->raw,
            heldAt: $now,
        );
    }

    /** The same identity, now bound to the account that just authenticated. */
    public function awaiting(string $subjectId): self
    {
        return new self(
            provider: $this->provider,
            subject: $this->subject,
            email: $this->email,
            name: $this->name,
            raw: $this->raw,
            heldAt: $this->heldAt,
            awaitingSubjectId: $subjectId,
        );
    }

    public function expired(int $now): bool
    {
        // `>=` rather than `>`, and no tolerance either side: a window that is generous
        // at its edge is a window with a different length than the one documented.
        return $now - $this->heldAt >= self::TTL_SECONDS;
    }

    /** The provider's human name — "GitHub", not "social:github". */
    public function label(): string
    {
        return SocialProviders::label(str_replace('social:', '', $this->provider));
    }

    public function principal(): FederatedPrincipal
    {
        return new FederatedPrincipal(
            provider: $this->provider,
            subject: $this->subject,
            email: $this->email,
            name: $this->name,
            raw: $this->raw,
        );
    }

    /**
     * Session storage is a serialization boundary, so this is where the typed value
     * flattens — and the only place it does.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'subject' => $this->subject,
            'email' => $this->email,
            'name' => $this->name,
            'raw' => $this->raw,
            'held_at' => $this->heldAt,
            'awaiting_subject_id' => $this->awaitingSubjectId,
        ];
    }

    /**
     * Rebuild from session, or null when the payload is not one we wrote.
     *
     * Returning null rather than filling in defaults: a half-readable payload is a
     * session that has been tampered with or a format that has moved on, and either way
     * inventing the missing half would be inventing consent.
     */
    public static function fromArray(mixed $stored): ?self
    {
        if (! is_array($stored)) {
            return null;
        }

        $provider = $stored['provider'] ?? null;
        $subject = $stored['subject'] ?? null;
        $heldAt = $stored['held_at'] ?? null;

        if (! is_string($provider) || ! is_string($subject) || ! is_int($heldAt)) {
            return null;
        }

        $raw = $stored['raw'] ?? [];
        $awaiting = $stored['awaiting_subject_id'] ?? null;

        return new self(
            provider: $provider,
            subject: $subject,
            email: is_string($stored['email'] ?? null) ? $stored['email'] : null,
            name: is_string($stored['name'] ?? null) ? $stored['name'] : null,
            raw: is_array($raw) ? array_filter($raw, 'is_string', ARRAY_FILTER_USE_KEY) : [],
            heldAt: $heldAt,
            awaitingSubjectId: is_string($awaiting) ? $awaiting : null,
        );
    }
}
