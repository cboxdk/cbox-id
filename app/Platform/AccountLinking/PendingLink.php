<?php

declare(strict_types=1);

namespace App\Platform\AccountLinking;

use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;

/**
 * A provider identity held aside while its owner proves they control the account that
 * already uses its address — the state behind "Link your Google account to sn@cbox.dk?".
 *
 * WHAT IS HELD, AND WHAT IS NOT. The provider's stable subject (`sub`), never its address,
 * is what the link is made on: an address can be reassigned at the provider, and a link
 * keyed on one would hand the account to whoever holds it next. The connection id comes
 * along because an SSO connection's subjects are namespaced by it — a principal rebuilt
 * without it would link an identity that the next sign-in through the same connection
 * cannot find. The account it is waiting on is held by id, so nothing the browser submits
 * can point the step at somebody else.
 *
 * WHERE IT LIVES. In the server-side session, never in the page or the URL. Three more
 * properties do the security work:
 *
 *  - `heldAt` bounds the window to {@see TTL_SECONDS}. A link is a permanent new way into
 *    an account; an identity that sat in a session for an hour is not evidence of anything.
 *  - `id` is random and is posted back by the page, so an answer typed into a tab opened
 *    for one link cannot complete a different one that replaced it in the meantime.
 *  - The whole payload is SIGNED with the application key when it is written, and the
 *    signature is checked every time it is read ({@see LinkHold}). A session store is
 *    server-side, but it is also a cache or a database row that other code writes to; a
 *    hold that was altered there — a different subject, a different account — reads as no
 *    hold at all rather than as an instruction.
 *
 * Single-use: the hold is pulled before the link is written, so a replayed answer finds
 * nothing.
 */
final readonly class PendingLink
{
    /** Ten minutes: long enough to find a code in an inbox, short enough not to linger. */
    public const TTL_SECONDS = 600;

    /** Wrong answers a hold survives. The account's own lockout counts them too. */
    public const MAX_FAILURES = 5;

    /**
     * @param  array<string, mixed>  $raw  the provider's claims, as the link will store them
     */
    public function __construct(
        public string $id,
        public string $subjectId,
        public string $accountEmail,
        public string $providerLabel,
        public string $providerKey,
        public string $provider,
        public string $subject,
        public ?string $email,
        public ?string $name,
        public ?string $connectionId,
        public ?bool $emailVerified,
        public array $raw,
        public ?string $operatorProvider,
        public bool $challenged,
        public int $heldAt,
        public int $failures = 0,
        public bool $codeSent = false,
    ) {}

    public static function hold(
        FederatedPrincipal $principal,
        string $subjectId,
        string $accountEmail,
        string $providerLabel,
        string $providerKey,
        ?string $operatorProvider,
        bool $challenged,
        int $now,
    ): self {
        return new self(
            id: bin2hex(random_bytes(16)),
            subjectId: $subjectId,
            accountEmail: $accountEmail,
            providerLabel: $providerLabel,
            providerKey: $providerKey,
            provider: $principal->provider,
            subject: $principal->subject,
            email: $principal->email,
            name: $principal->name,
            connectionId: $principal->connectionId,
            emailVerified: $principal->emailVerified,
            raw: $principal->raw,
            operatorProvider: $operatorProvider,
            challenged: $challenged,
            heldAt: $now,
        );
    }

    public function expired(int $now): bool
    {
        // `>=`, no tolerance: a window generous at its edge is a different length than documented.
        return $now - $this->heldAt >= self::TTL_SECONDS;
    }

    /** The same hold, one more wrong answer down. */
    public function failed(): self
    {
        return $this->with($this->failures + 1, $this->codeSent);
    }

    /** The same hold, with a code on its way to the account's inbox. */
    public function sentCode(): self
    {
        return $this->with($this->failures, true);
    }

    public function exhausted(): bool
    {
        return $this->failures >= self::MAX_FAILURES;
    }

    /** The principal as the provider asserted it — connection id included, see the class docblock. */
    public function principal(): FederatedPrincipal
    {
        return new FederatedPrincipal(
            provider: $this->provider,
            subject: $this->subject,
            email: $this->email,
            name: $this->name,
            connectionId: $this->connectionId,
            emailVerified: $this->emailVerified,
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
            'id' => $this->id,
            'subject_id' => $this->subjectId,
            'account_email' => $this->accountEmail,
            'provider_label' => $this->providerLabel,
            'provider_key' => $this->providerKey,
            'provider' => $this->provider,
            'subject' => $this->subject,
            'email' => $this->email,
            'name' => $this->name,
            'connection_id' => $this->connectionId,
            'email_verified' => $this->emailVerified,
            'raw' => $this->raw,
            'operator_provider' => $this->operatorProvider,
            'challenged' => $this->challenged,
            'held_at' => $this->heldAt,
            'failures' => $this->failures,
            'code_sent' => $this->codeSent,
        ];
    }

    /**
     * Rebuild from what {@see toArray()} wrote, or null when the payload is not one we wrote.
     *
     * Null rather than defaults: a half-readable payload is a session that was tampered
     * with or a format that has moved on, and inventing the missing half would be
     * inventing consent.
     */
    public static function fromArray(mixed $stored): ?self
    {
        if (! is_array($stored)) {
            return null;
        }

        $strings = ['id', 'subject_id', 'account_email', 'provider_label', 'provider_key', 'provider', 'subject'];

        foreach ($strings as $key) {
            if (! is_string($stored[$key] ?? null) || $stored[$key] === '') {
                return null;
            }
        }

        if (! is_int($stored['held_at'] ?? null) || ! is_int($stored['failures'] ?? null)) {
            return null;
        }

        $raw = $stored['raw'] ?? [];
        $nullableString = static fn (string $key): ?string => is_string($stored[$key] ?? null) ? $stored[$key] : null;

        return new self(
            id: $stored['id'],
            subjectId: $stored['subject_id'],
            accountEmail: $stored['account_email'],
            providerLabel: $stored['provider_label'],
            providerKey: $stored['provider_key'],
            provider: $stored['provider'],
            subject: $stored['subject'],
            email: $nullableString('email'),
            name: $nullableString('name'),
            connectionId: $nullableString('connection_id'),
            emailVerified: is_bool($stored['email_verified'] ?? null) ? $stored['email_verified'] : null,
            raw: is_array($raw) ? array_filter($raw, 'is_string', ARRAY_FILTER_USE_KEY) : [],
            operatorProvider: $nullableString('operator_provider'),
            challenged: ($stored['challenged'] ?? false) === true,
            heldAt: $stored['held_at'],
            failures: $stored['failures'],
            codeSent: ($stored['code_sent'] ?? false) === true,
        );
    }

    /** A copy with the two fields that change while the hold is live. */
    private function with(int $failures, bool $codeSent): self
    {
        return new self(
            id: $this->id,
            subjectId: $this->subjectId,
            accountEmail: $this->accountEmail,
            providerLabel: $this->providerLabel,
            providerKey: $this->providerKey,
            provider: $this->provider,
            subject: $this->subject,
            email: $this->email,
            name: $this->name,
            connectionId: $this->connectionId,
            emailVerified: $this->emailVerified,
            raw: $this->raw,
            operatorProvider: $this->operatorProvider,
            challenged: $this->challenged,
            heldAt: $this->heldAt,
            failures: $failures,
            codeSent: $codeSent,
        );
    }
}
