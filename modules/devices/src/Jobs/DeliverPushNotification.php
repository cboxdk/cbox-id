<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Jobs;

use Cbox\Id\Devices\Contracts\PushDispatcher;
use Cbox\Id\Devices\Models\PushNotification;
use Cbox\Id\Devices\Support\DeviceConfig;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one already-written notification.
 *
 * The job carries an ID, never a model: a serialised Device would drag a sealed push
 * token through the queue store, where it would sit in plaintext-adjacent form in a
 * table nobody thinks of as secret.
 *
 * Delivery is asynchronous rather than inline for the same reason webhook delivery is —
 * a handset that blackholes its connection would otherwise stall the CIBA request that
 * created the push. On QUEUE_CONNECTION=sync that protection is absent by definition
 * and the send runs inside the originating request, bounded only by the transport's own
 * timeouts. That is the documented trade, not an oversight.
 */
class DeliverPushNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Unique-lock ceiling, deliberately the SAME window the retry sweep uses to decide a
     * Pending notification is stranded. While the lock holds, a job for this
     * notification is presumed alive and the sweep's re-enqueue is correctly suppressed
     * as a duplicate; the moment it counts as stranded the lock has expired, so the
     * rescue can actually fire. A longer ceiling wedges the rescue permanently shut.
     */
    public int $uniqueFor;

    public function __construct(public readonly string $notificationId)
    {
        $this->uniqueFor = max(60, DeviceConfig::int('id-devices.stranded_after_seconds', 900));
    }

    public function uniqueId(): string
    {
        return $this->notificationId;
    }

    public function handle(EnvironmentContext $context, PushDispatcher $dispatcher): void
    {
        // A worker has no ambient environment, and every model here is environment-owned
        // with a deny-by-default scope — so read the row's own environment across the
        // boundary first, then act inside it. Resolving the context per call (rather
        // than capturing it) matters: the binding is scoped, and a captured copy would
        // pin this worker to whichever environment its first job happened to belong to.
        $environmentId = $context->withoutScope(
            fn (): mixed => PushNotification::query()->whereKey($this->notificationId)->value('environment_id'),
        );

        if (! is_string($environmentId) || $environmentId === '') {
            return;
        }

        $context->runAs(GenericEnvironment::of($environmentId), function () use ($dispatcher): void {
            $dispatcher->deliver($this->notificationId);
        });
    }
}
