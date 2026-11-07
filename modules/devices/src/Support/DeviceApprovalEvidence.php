<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Support;

use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Illuminate\Support\Facades\DB;

/** Evidence from the device-proof-protected API, never from a browser approval. */
final class DeviceApprovalEvidence
{
    public function record(string $requestId, Introspection $token): void
    {
        $device = Device::query()->where('subject_id', $token->subject)
            ->where('dpop_jkt', $token->confirmationThumbprint())
            ->whereNotNull('dpop_jkt')->where('status', '!=', DeviceStatus::Revoked->value)->first();

        if ($token->confirmationThumbprint() !== null && $device !== null) {
            DB::table('id_device_approval_evidence')->insert([
                'request_id' => $requestId, 'device_id' => $device->id,
                'environment_id' => app(EnvironmentContext::class)->requireEnvironment()->environmentKey(),
            ]);
        }
    }

    public function valid(string $requestId, string $subject, bool $lock = false): bool
    {
        $id = DB::table('id_device_approval_evidence')->where('request_id', $requestId)
            ->where('environment_id', app(EnvironmentContext::class)->requireEnvironment()->environmentKey())->value('device_id');

        if (! is_string($id)) {
            return false;
        }
        $device = Device::query()->whereKey($id)->where('subject_id', $subject)->whereNotNull('dpop_jkt')->first();
        if ($device === null) {
            return false;
        }
        // Match the API gate: revoking any registration of a device key ends that
        // key's authority, including duplicate installations using the same key.
        $devices = Device::query()->where('subject_id', $subject)->where('dpop_jkt', $device->dpop_jkt)
            ->when($lock, fn ($query) => $query->lockForUpdate())->get();

        return $devices->contains(fn (Device $candidate): bool => $candidate->id === $id)
            && ! $devices->contains(fn (Device $candidate): bool => $candidate->status === DeviceStatus::Revoked);
    }
}
