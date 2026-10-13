<?php

declare(strict_types=1);

namespace App\Platform\Passkeys;

use Carbon\CarbonImmutable;
use Cbox\Id\Identity\Contracts\Passkeys;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use DateTimeInterface;

/**
 * The framework's passkey service, plus WHEN each passkey last signed somebody in.
 *
 * A decorator rather than a stamp in each controller, because a passkey signs people in
 * at more than one door — the hosted sign-in page, the headless sign-in API — and a stamp
 * at one of them would leave "last used" quietly wrong for the rest. Everything else is
 * the framework's, untouched: registration, verification, the clone guard.
 *
 * Stamped only AFTER the assertion verified: a failed or refused attempt is not a use.
 */
final readonly class RecordsPasskeyUse implements Passkeys
{
    public function __construct(private Passkeys $inner) {}

    public function register(string $userId, string $challenge, string $clientResponseJson, ?string $name = null): WebAuthnCredential
    {
        return $this->inner->register($userId, $challenge, $clientResponseJson, $name);
    }

    public function authenticate(string $credentialId, string $challenge, string $clientResponseJson): string
    {
        $subjectId = $this->inner->authenticate($credentialId, $challenge, $clientResponseJson);

        // Through the base query so the row's `updated_at` keeps meaning "changed", and
        // under the same environment scope the framework found the credential in.
        WebAuthnCredential::query()
            ->where('credential_id', $credentialId)
            ->toBase()
            ->update(['last_used_at' => now()]);

        return $subjectId;
    }

    public function credentialById(string $credentialId): ?WebAuthnCredential
    {
        return $this->inner->credentialById($credentialId);
    }

    /**
     * When `$passkey` last signed somebody in, or null. Read here because the framework's
     * model does not know the column — it arrives as whatever the driver returns.
     */
    public static function lastUsedAt(WebAuthnCredential $passkey): ?CarbonImmutable
    {
        $value = $passkey->getAttribute('last_used_at');

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
