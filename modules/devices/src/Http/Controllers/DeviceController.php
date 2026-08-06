<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Http\Controllers;

use Cbox\Id\Devices\Enums\DevicePlatform;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Support\EnrolmentCodeRejected;
use Cbox\Id\Devices\Support\EnrolmentToken;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The authenticator app's own device registry.
 *
 * Every route here is scoped to the CALLER: the subject comes from the verified access
 * token, never from the request body, so there is no way to enrol, list or remove
 * someone else's handset regardless of what is posted.
 */
final class DeviceController
{
    public function __construct(
        private readonly SecretBox $secretBox,
        private readonly AuditLog $audit,
        private readonly EnrolmentToken $enrolmentCodes,
    ) {}

    /**
     * Enrol, or re-enrol after an FCM token rotation.
     *
     * Idempotent on `install_id` so the app can simply POST again whenever its token
     * changes — there is no separate rotation endpoint to get wrong, and no ordering
     * hazard between "rotate" and "re-read my device id".
     */
    public function store(Request $request): JsonResponse
    {
        $token = $this->token($request);
        $subjectId = $this->subject($token);

        $request->validate([
            'install_id' => ['required', 'string', 'size:26'],
            'platform' => ['required', Rule::enum(DevicePlatform::class)],
            'push_token' => ['required', 'string', 'max:4096'],
            // Rendered in an admin console, so control characters are stripped rather
            // than trusted — this is user-supplied text that ends up in someone else's UI.
            'name' => ['required', 'string', 'max:100'],
            'app_version' => ['nullable', 'string', 'max:32'],
            'os_version' => ['nullable', 'string', 'max:32'],
            'model' => ['nullable', 'string', 'max:64'],
            'enrolment_token' => ['nullable', 'string', 'max:4096'],
        ]);

        $installId = $request->string('install_id')->toString();

        $existing = Device::query()
            ->where('install_id', $installId)
            ->first();

        // A FIRST enrolment must present the short-lived code from the console. A
        // re-enrolment — which is how the app rotates its push token — must not, because
        // that happens in the background with no screen to scan from. That distinction is
        // why this is not simply a required field.
        if ($existing === null) {
            try {
                $this->enrolmentCodes->consume(
                    $request->string('enrolment_token')->toString(),
                    $subjectId,
                    $installId,
                );
            } catch (EnrolmentCodeRejected $e) {
                $this->audit->record(new AuditEvent(
                    action: 'device.enrolment_refused',
                    actorType: ActorType::User,
                    actorId: $subjectId,
                    targetType: 'device',
                    targetId: $installId,
                    context: ['reason' => $e->getMessage()],
                    ip: $request->ip(),
                ));

                // One message for every reason. Distinguishing "already used" from
                // "belongs to someone else" would let a caller probe for which codes
                // exist and whose they are. The specific reason goes to the audit log.
                return new JsonResponse([
                    'error' => 'enrolment_code_invalid',
                    'message' => 'That enrolment code is not valid. Open Trusted devices for a fresh one.',
                ], 422);
            }
        }

        // An install already claimed by somebody else. Refuse rather than reassign:
        // silently moving it would let one user capture another's push stream by
        // guessing an install id.
        if ($existing !== null && $existing->subject_id !== $subjectId) {
            return new JsonResponse([
                'error' => 'install_claimed',
                'message' => 'This installation is registered to a different user.',
            ], 409);
        }

        $device = $existing ?? new Device;
        $isNew = $existing === null;

        $device->fill([
            'subject_id' => $subjectId,
            'install_id' => $installId,
            'platform' => DevicePlatform::from($request->string('platform')->toString()),
            'name' => $this->sanitise($request->string('name')->toString()),
            'status' => DeviceStatus::Active,
            'app_version' => $this->optional($request, 'app_version'),
            'os_version' => $this->optional($request, 'os_version'),
            'model' => $this->optional($request, 'model'),
            'last_seen_at' => Carbon::now(),
        ]);

        // Re-enrolling clears any breaker state and revives a handset that was retired
        // by a permanent transport error — a fresh token is exactly the evidence needed
        // to believe in it again.
        $device->consecutive_failures = 0;
        $device->circuit_opened_at = null;
        $device->last_error = null;

        // Taken from the VERIFIED proof, never the body: a client-asserted thumbprint
        // would be worth nothing.
        $device->dpop_jkt = $token->confirmationThumbprint();

        $device->save();

        $device->token_encrypted = $this->secretBox->seal(
            $request->string('push_token')->toString(),
            $device->secretContext(),
        );
        $device->token_rotated_at = Carbon::now();
        $device->save();

        $this->audit->record(new AuditEvent(
            action: $isNew ? 'device.enrolled' : 'device.token_rotated',
            actorType: ActorType::User,
            actorId: $subjectId,
            targetType: 'device',
            targetId: $device->id,
            context: ['platform' => $device->platform->value, 'name' => $device->name],
            ip: $request->ip(),
        ));

        return new JsonResponse(['data' => $this->present($device)], $isNew ? 201 : 200);
    }

    public function index(Request $request): JsonResponse
    {
        $devices = Device::query()
            ->where('subject_id', $this->subject($this->token($request)))
            ->orderByDesc('last_seen_at')
            ->limit(50)
            ->get();

        return new JsonResponse([
            'data' => $devices->map(fn (Device $d): array => $this->present($d))->all(),
            'meta' => ['limit' => 50, 'has_more' => false, 'next_cursor' => null],
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $subjectId = $this->subject($this->token($request));

        $device = Device::query()
            ->whereKey($id)
            ->where('subject_id', $subjectId)
            ->first();

        // Someone else's device answers exactly as a missing one does. A distinguishable
        // response would confirm the existence of another user's handset.
        if ($device === null) {
            return new JsonResponse(['error' => 'not_found', 'message' => 'No such device.'], 404);
        }

        $device->delete();

        $this->audit->record(new AuditEvent(
            action: 'device.removed',
            actorType: ActorType::User,
            actorId: $subjectId,
            targetType: 'device',
            targetId: $id,
            ip: $request->ip(),
        ));

        return new JsonResponse(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Device $device): array
    {
        // `push_token` is deliberately absent. It is a capability, not an attribute, and
        // nothing outside a delivery attempt ever needs to see it.
        return [
            'id' => $device->id,
            'install_id' => $device->install_id,
            'platform' => $device->platform->value,
            'name' => $device->name,
            'status' => $device->status->value,
            'app_version' => $device->app_version,
            'os_version' => $device->os_version,
            'model' => $device->model,
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'created_at' => $device->created_at?->toIso8601String(),
        ];
    }

    /** An optional string field, where absent and empty both mean "not provided". */
    private function optional(Request $request, string $key): ?string
    {
        $value = $request->string($key)->toString();

        return $value === '' ? null : $value;
    }

    private function sanitise(string $name): string
    {
        $stripped = preg_replace('/[\p{C}]/u', '', $name) ?? $name;

        return mb_substr(trim($stripped), 0, 100);
    }

    private function token(Request $request): Introspection
    {
        $token = $request->attributes->get('cbox_token');

        // Unreachable behind `scope:`, which sets this or short-circuits. Stated rather
        // than assumed so the failure mode is a 401 and not a TypeError.
        abort_unless($token instanceof Introspection, 401);

        return $token;
    }

    private function subject(Introspection $token): string
    {
        $subject = $token->subject;

        // A client-credentials token has no subject. There is no user whose devices
        // these would be, so there is nothing sensible to do but refuse.
        abort_if($subject === null || $subject === '', 403);

        return $subject;
    }
}
