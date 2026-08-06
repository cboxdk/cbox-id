<?php

declare(strict_types=1);

namespace Cbox\Id\Devices;

use Cbox\Id\Devices\Contracts\PushDispatcher;
use Cbox\Id\Devices\Contracts\PushTransport;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Enums\NotificationKind;
use Cbox\Id\Devices\Enums\NotificationStatus;
use Cbox\Id\Devices\Jobs\DeliverPushNotification;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Models\PushNotification;
use Cbox\Id\Devices\Support\DeviceCircuitBreaker;
use Cbox\Id\Devices\Support\DeviceConfig;
use Cbox\Id\Devices\ValueObjects\PushMessage;
use Cbox\Id\Devices\ValueObjects\PushPayload;
use Cbox\Id\Devices\ValueObjects\PushResult;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes notification rows, hands them to the queue, and settles them.
 *
 * Modelled deliberately on HttpWebhookDispatcher — same durability contract, same
 * sequence allocation, same stranded-job rescue — because that pipeline has already
 * paid for these lessons in production and there is no value in rediscovering them.
 */
class QueuedPushDispatcher implements PushDispatcher
{
    public function __construct(
        private readonly Container $container,
        private readonly DeviceCircuitBreaker $breaker,
        private readonly SecretBox $secretBox,
    ) {}

    public function dispatch(
        string $subjectId,
        NotificationKind $kind,
        PushPayload $payload,
        ?DateTimeInterface $expiresAt = null,
    ): int {
        $devices = Device::query()
            ->where('subject_id', $subjectId)
            ->notifiable()
            ->get();

        $written = 0;

        foreach ($devices as $device) {
            $notification = new PushNotification;
            $notification->fill([
                'device_id' => $device->id,
                'sequence' => $this->nextSequence($device),
                'kind' => $kind,
                'status' => NotificationStatus::Pending,
                'payload' => $payload,
                'attempt' => 0,
                'expires_at' => $expiresAt === null ? null : Carbon::createFromInterface($expiresAt),
            ]);
            $notification->save();

            // The row is durable BEFORE the job exists, so a queue that drops the
            // message still leaves the work visible to retryPending().
            $this->enqueue($notification);

            $written++;
        }

        return $written;
    }

    public function deliver(string $notificationId): void
    {
        $notification = PushNotification::query()->whereKey($notificationId)->first();

        // Already settled. A duplicate job, or a message replayed by the queue, must not
        // re-send a delivered push or resurrect a dead-lettered one.
        if ($notification === null || $notification->status->isTerminal()) {
            return;
        }

        // Past its deadline — checked BEFORE the device lookup so an expired approval
        // costs nothing. A prompt that lands after the CIBA window has closed is worse
        // than no prompt: the user taps Approve and gets an error they cannot act on.
        if ($notification->expires_at !== null && $notification->expires_at->isPast()) {
            $notification->settle(NotificationStatus::Expired, 'Deadline passed before delivery.');

            return;
        }

        $device = Device::query()->whereKey($notification->device_id)->first();

        // The device was removed, or retired, between the row being written and the job
        // running. SETTLE rather than return: a row left Pending with no device behind
        // it is unsendable, unprunable, and re-selected by the stranded rescue on every
        // sweep from then on.
        if ($device === null || ! $device->isNotifiable()) {
            $notification->settle(NotificationStatus::Exhausted, 'Device is no longer enrolled.');

            return;
        }

        $this->attempt($device, $notification);
    }

    /**
     * The sweep is the ONE genuinely environment-spanning step in this module.
     *
     * It runs from the scheduler, and a scheduler process has no ambient environment:
     * EnvironmentContext is a `scoped` binding that starts null and is only ever
     * populated by an HTTP middleware or by a job re-entering its own environment. Every
     * model here is EnvironmentOwned with a deny-by-default scope, so selecting inside
     * that null context compiles to `WHERE 1 = 0` — the sweep would silently return zero
     * forever while passing any test that happened to set an environment first, and the
     * module's whole "durable row before enqueue" promise would be inert in production.
     *
     * So selection happens under withoutScope(), deliberately reading across the tenancy
     * boundary, and each row is then handed to a job that re-enters its OWN environment
     * before touching anything. That is the same split PumpAuditStreamsCommand uses, and
     * the reason DeliverPushNotification already reconstructs its environment on the way
     * in rather than trusting an ambient one.
     */
    public function retryPending(int $limit): int
    {
        return $this->container->make(EnvironmentContext::class)->withoutScope(
            fn (): int => $this->sweep($limit),
        );
    }

    private function sweep(int $limit): int
    {
        // Settle orphans first, and do it as a predicate rather than by skipping after
        // selection: the sweep is ordered by created_at ascending, so orphans would sit
        // permanently at its head, consuming every slot before any live work was reached.
        $this->terminaliseOrphans();

        // Same argument for rows whose deadline has passed. Left in place they are
        // undeliverable but still selectable, and — being the oldest rows in the table —
        // they would occupy the head of the ordering indefinitely.
        $this->terminaliseExpired();

        $due = PushNotification::query()
            ->whereIn('device_id', Device::query()->select('id'))
            ->where(fn (Builder $query): Builder => $query
                // A recorded failure whose backoff window has elapsed.
                ->where(fn (Builder $failed): Builder => $failed
                    ->where('status', NotificationStatus::Failed->value)
                    ->whereNotNull('next_retry_at')
                    ->where('next_retry_at', '<=', Carbon::now()))
                // ...or one that was written and queued but never processed: the job was
                // lost, the worker died, the queue was flushed. This clause is what makes
                // "durable before enqueued" true rather than aspirational.
                ->orWhere(fn (Builder $stranded): Builder => $stranded
                    ->where('status', NotificationStatus::Pending->value)
                    ->where('created_at', '<=', Carbon::now()->subSeconds($this->strandedAfterSeconds()))))
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $queued = 0;

        foreach ($due as $notification) {
            $this->enqueue($notification);
            $queued++;
        }

        return $queued;
    }

    private function attempt(Device $device, PushNotification $notification): void
    {
        // The breaker is open: park the notification until the cooldown elapses WITHOUT
        // charging it an attempt. The trip is the device's fault, not this
        // notification's, and charging it would burn a dead handset's whole backlog
        // through all twelve attempts inside a single cooldown window.
        if (! $this->breaker->shouldAttempt($device)) {
            $notification->status = NotificationStatus::Failed;
            $notification->next_retry_at = $this->breaker->closesAt($device);
            $notification->save();

            return;
        }

        $notification->attempt++;

        // Captured before the round trip: this is the exact token the attempt is about,
        // and the only thing a later retire may act on.
        $attempted = (string) $device->token_encrypted;

        $result = $this->send($device, $notification);

        // Nothing was learned about the handset, so the device is not touched at all —
        // no failure recorded, no breaker movement.
        if (! $result->configured) {
            $notification->settle(NotificationStatus::Skipped, $result->error, $result->code);

            return;
        }

        if ($result->delivered) {
            $this->breaker->recordSuccess($device);

            $notification->settle(NotificationStatus::Delivered, null, $result->code);

            return;
        }

        $this->breaker->recordFailure($device, $result->error);

        // A token the provider has rejected outright will not start working again.
        // Retire it now, and clear it — a retired device must not keep a live push
        // capability sitting in the database.
        if ($result->permanent) {
            $this->retire($device, $attempted);

            $notification->settle(NotificationStatus::Exhausted, $result->error, $result->code);

            return;
        }

        $this->scheduleRetry($notification, $result);
    }

    /**
     * Unseal the token and hand it to the transport.
     *
     * The plaintext token is built here, at the last possible moment, and never reaches
     * a queue payload or a log line. A transport that throws is treated as a TRANSIENT
     * failure rather than propagating: a broken or misconfigured transport must degrade
     * into retries, never into a failed login for the user waiting on the approval.
     */
    private function send(Device $device, PushNotification $notification): PushResult
    {
        try {
            $token = $this->secretBox->open((string) $device->token_encrypted, $device->secretContext());

            return $this->container->make(PushTransport::class)->send(new PushMessage(
                token: $token,
                platform: $device->platform,
                kind: $notification->kind,
                payload: $notification->payload,
            ));
        } catch (Throwable $e) {
            return PushResult::transientFailure($e->getMessage());
        }
    }

    private function scheduleRetry(PushNotification $notification, PushResult $result): void
    {
        $maxAttempts = max(1, DeviceConfig::int('id-devices.max_attempts', 12));

        if ($notification->attempt >= $maxAttempts) {
            $notification->settle(NotificationStatus::Exhausted, $result->error, $result->code);

            return;
        }

        $notification->status = NotificationStatus::Failed;
        // Exponential, capped at an hour: beyond that the backoff is longer than most
        // notifications are worth, and an approval prompt has expired long since.
        $notification->next_retry_at = Carbon::now()->addMinutes(min(60, 2 ** $notification->attempt));

        if ($result->error !== null) {
            $notification->last_error = mb_substr($result->error, 0, 255);
        }

        $notification->response_code = $result->code;
        $notification->save();
    }

    /**
     * Allocate the next per-device sequence ATOMICALLY.
     *
     * A plain increment() bumps the database correctly but sets the in-memory attribute
     * optimistically (loaded + 1, with no re-read), so two concurrent workers stamp the
     * SAME number — defeating the gap detection the sequence exists for. The
     * lockForUpdate read-modify-write serialises them; the unique (device_id, sequence)
     * index is the backstop.
     */
    private function nextSequence(Device $device): int
    {
        return DB::transaction(function () use ($device): int {
            $locked = Device::query()->whereKey($device->id)->lockForUpdate()->first();
            $next = ($locked ?? $device)->last_sequence + 1;

            Device::query()->whereKey($device->id)->update(['last_sequence' => $next]);

            return $next;
        });
    }

    /**
     * Settle every notification whose device no longer exists. A single set-based
     * UPDATE: this runs on the per-minute sweep, and the population it fixes is created
     * in bulk — deleting one device orphans every notification it ever had.
     */
    private function terminaliseOrphans(): void
    {
        PushNotification::query()
            ->whereIn('status', [NotificationStatus::Pending->value, NotificationStatus::Failed->value])
            ->whereNotIn('device_id', Device::query()->select('id'))
            ->update([
                'status' => NotificationStatus::Exhausted->value,
                'next_retry_at' => null,
                'last_error' => 'Device was removed.',
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * Retire a device whose token the provider rejected outright.
     *
     * Conditional on the exact ciphertext this attempt used. Between loading the device
     * and hearing back from FCM, the app may have re-enrolled with a rotated token — and
     * an unconditional write would then clear the NEW token because the OLD one was
     * rejected. The user's app got a 200 from enrolment, has no reason to retry, and
     * simply stops receiving approval prompts. Matching on the ciphertext means a
     * rotation makes this update affect nothing, which is exactly right.
     */
    private function retire(Device $device, string $attemptedCiphertext): void
    {
        $retired = Device::query()
            ->whereKey($device->id)
            ->where('token_encrypted', $attemptedCiphertext)
            ->update([
                'status' => DeviceStatus::Retired->value,
                'token_encrypted' => null,
                'updated_at' => Carbon::now(),
            ]);

        if ($retired > 0) {
            $device->forceFill(['status' => DeviceStatus::Retired, 'token_encrypted' => null]);
        }
    }

    /**
     * Settle every notification whose deadline has passed.
     *
     * Done set-based, and BEFORE selection, for a reason specific to approvals: the
     * stranded window is 900s while a CIBA request lives 300s, so any approval the
     * stranded clause could rescue has already expired by construction. Re-enqueueing
     * them would spend one queue job each to reach a conclusion a single UPDATE reaches
     * for all of them — and until they were settled they would sit at the head of the
     * created_at ordering, crowding out live work.
     */
    private function terminaliseExpired(): void
    {
        PushNotification::query()
            ->whereIn('status', [NotificationStatus::Pending->value, NotificationStatus::Failed->value])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', Carbon::now())
            ->update([
                'status' => NotificationStatus::Expired->value,
                'next_retry_at' => null,
                'last_error' => 'Deadline passed before delivery.',
                'updated_at' => Carbon::now(),
            ]);
    }

    private function enqueue(PushNotification $notification): void
    {
        $pending = DeliverPushNotification::dispatch($notification->id);

        $connection = DeviceConfig::nullableString('id-devices.queue_connection');

        if ($connection !== null) {
            $pending->onConnection($connection);
        }

        $queue = DeviceConfig::nullableString('id-devices.queue');

        if ($queue !== null) {
            $pending->onQueue($queue);
        }
    }

    private function strandedAfterSeconds(): int
    {
        return max(60, DeviceConfig::int('id-devices.stranded_after_seconds', 900));
    }
}
