<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Erasure;

use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Models\EnrolmentCode;
use Cbox\Id\Devices\Models\PushNotification;
use Cbox\Id\Identity\Contracts\ErasureStep;
use Cbox\Id\Identity\ValueObjects\ErasureRequest;
use Cbox\Id\Identity\ValueObjects\ErasureStepResult;

/**
 * The person's enrolled handsets, every push sent to them, and the enrolment codes they
 * redeemed — deleted, not revoked.
 *
 * A device row is a sealed push token, a model, an OS version and an install id: enough
 * to recognise the phone, and the token still reaches it. Revoking would stop the pushes
 * and keep the description of the handset, which is the half an erasure is about. The
 * notifications go with it because their payloads name what the person was asked to
 * approve, and where.
 *
 * Registered by the module beside the tables it adds, the way the framework's modules do,
 * so the host's erasure reaches them without knowing the module exists.
 */
final class DevicesErasureStep implements ErasureStep
{
    public function name(): string
    {
        return 'devices.devices';
    }

    public function erase(ErasureRequest $request): ErasureStepResult
    {
        $devices = Device::query()->where('subject_id', $request->subjectId)->pluck('id')->all();

        $notifications = $devices === [] ? 0 : PushNotification::query()->whereIn('device_id', $devices)->toBase()->delete();
        $deleted = $devices === [] ? 0 : Device::query()->whereKey($devices)->toBase()->delete();
        $codes = EnrolmentCode::query()->where('subject_id', $request->subjectId)->toBase()->delete();

        return ErasureStepResult::of($this->name(), [
            'devices' => $deleted,
            'push_notifications' => $notifications,
            'enrolment_codes' => $codes,
        ]);
    }
}
