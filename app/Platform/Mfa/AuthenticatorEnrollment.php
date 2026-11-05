<?php

declare(strict_types=1);

namespace App\Platform\Mfa;

use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\TotpDevices;
use Cbox\Id\Identity\Models\MfaFactor;
use Illuminate\Support\Facades\DB;

final readonly class AuthenticatorEnrollment
{
    public function __construct(private Mfa $mfa, private TotpDevices $devices) {}

    public function confirm(string $userId, string $factorId, string $code): ?ConfirmedAuthenticator
    {
        return DB::transaction(function () use ($userId, $factorId, $code): ?ConfirmedAuthenticator {
            // Same ordered locks as the credential service. Concurrent confirmations of
            // two first apps must not each replace the other's newly shown recovery codes.
            MfaFactor::query()->where('user_id', $userId)->where('type', 'totp')
                ->orderBy('id')->lockForUpdate()->get();
            $first = ! $this->mfa->hasConfirmedTotp($userId);
            if (! $this->devices->confirmDevice($userId, $factorId, $code)) {
                return null;
            }

            // A user may already have recovery codes from SMS enrolment.
            return new ConfirmedAuthenticator($first && $this->mfa->remainingRecoveryCodes($userId) === 0
                ? $this->mfa->generateRecoveryCodes($userId) : []);
        }, 3);
    }
}
