<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Support;

use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\OAuthServer\Models\RefreshToken;
use Illuminate\Support\Facades\DB;

final class RevokeAuthenticatorDevice
{
    public function revoke(Device $device): void
    {
        DB::transaction(function () use ($device): void {
            $locked = Device::query()->whereKey($device->id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }
            // Retain the key binding as a tombstone. A still-live access token must
            // not re-enrol or change install_id to resurrect the removed handset.
            $locked->forceFill(['status' => DeviceStatus::Revoked, 'token_encrypted' => null])->save();
            if ($locked->dpop_jkt !== null && $locked->dpop_jkt !== '') {
                RefreshToken::query()->where('user_id', $locked->subject_id)
                    ->where('jkt', $locked->dpop_jkt)->whereNull('revoked_at')
                    ->update(['revoked_at' => now(), 'successor_token' => null]);
            }
        }, 3);
    }
}
