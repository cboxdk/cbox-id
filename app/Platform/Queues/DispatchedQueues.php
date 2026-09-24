<?php

declare(strict_types=1);

namespace App\Platform\Queues;

/**
 * EVERY QUEUE THIS APPLICATION DISPATCHES TO — derived from the same settings the
 * dispatchers read, so the workers cannot drift from where the jobs actually go.
 *
 * Why this has to exist: the autoscaler DISCOVERS queues from metrics and gives a queue it
 * was not told about a worker floor of zero. A queue nobody named therefore waits for a
 * cold start on every job, and a queue a deployment moved (`CBOX_ID_WEBHOOKS_QUEUE=hooks`)
 * would silently lose the floor the old name had. Naming the queues by hand in the
 * autoscale config is the drift this closes: the config would say `default` while the
 * webhooks went to `hooks`.
 *
 * WHO DISPATCHES WHERE. Each entry below is a dispatcher with a connection/queue setting
 * of its own; a blank setting means "the application's default", which is where
 * everything else goes too — the framework's queued mail, notifications and listeners,
 * `DeliverBackchannelLogout`, `SyncAppManifestJob`, `PumpAuditStream` and
 * `DrainProvisioningConnection`, none of which name a queue:
 *
 *  - webhooks:        CBOX_ID_WEBHOOKS_QUEUE_CONNECTION / CBOX_ID_WEBHOOKS_QUEUE
 *  - push (devices):  CBOX_ID_DEVICES_QUEUE_CONNECTION  / CBOX_ID_DEVICES_QUEUE
 *  - Postal webhooks: POSTAL_WEBHOOK_CONNECTION          / POSTAL_WEBHOOK_QUEUE
 *  - Postal inbound:  POSTAL_INBOUND_CONNECTION          / POSTAL_INBOUND_QUEUE
 *  - SIEM streaming:  SIEM_QUEUE_CONNECTION              / SIEM_QUEUE
 *
 * A connection that runs jobs inline or nowhere (`sync`, `null`, `deferred`,
 * `background`, `failover`) has no worker to run, so it contributes nothing.
 */
readonly class DispatchedQueues
{
    /** Connections whose jobs never wait for a worker — nothing to supervise there. */
    private const INLINE_CONNECTIONS = ['sync', 'null', 'deferred', 'background', 'failover'];

    /**
     * @param  list<DispatchedQueue>  $queues  In priority order, each connection:queue once.
     */
    public function __construct(public array $queues = []) {}

    /**
     * Resolve the list from raw settings, as the config file reads them.
     *
     * @param  string  $defaultConnection  QUEUE_CONNECTION.
     * @param  array<string, string>  $defaultQueueFor  Each connection's own default queue name (REDIS_QUEUE, DB_QUEUE, …).
     * @param  list<array{0: mixed, 1: mixed}>  $routes  Each dispatcher's [connection, queue] override; blank means "the default".
     */
    public static function resolve(string $defaultConnection, array $defaultQueueFor, array $routes): self
    {
        $default = new DispatchedQueue($defaultConnection, $defaultQueueFor[$defaultConnection] ?? 'default');
        $resolved = [$default->key() => $default];

        foreach ($routes as [$connection, $queue]) {
            $connection = self::filled($connection) ?? $defaultConnection;
            $queue = self::filled($queue) ?? ($defaultQueueFor[$connection] ?? 'default');
            $target = new DispatchedQueue($connection, $queue);

            $resolved[$target->key()] ??= $target;
        }

        return new self(array_values(array_filter(
            $resolved,
            static fn (DispatchedQueue $queue): bool => ! in_array($queue->connection, self::INLINE_CONNECTIONS, true),
        )));
    }

    /**
     * The autoscaler's `groups` block: one worker group per connection, polling that
     * connection's queues in the order they were declared (the default queue first).
     *
     * A GROUP rather than one entry per queue, on purpose. Each named queue would carry
     * its own floor of one, so a deployment that split webhooks onto their own queue
     * would idle two workers on a 512 MB host where one polling both is plenty; a group
     * shares one scaled worker set across all of them, strict priority left to right.
     *
     * @param  class-string  $profile
     * @return array<string, array{connection: string, queues: list<string>, mode: string, profile: class-string}>
     */
    public function autoscaleGroups(string $profile): array
    {
        $groups = [];

        foreach ($this->queues as $queue) {
            $name = 'cbox-id-'.$queue->connection;

            $groups[$name] ??= ['connection' => $queue->connection, 'queues' => [], 'mode' => 'priority', 'profile' => $profile];
            $groups[$name]['queues'][] = $queue->queue;
        }

        return $groups;
    }

    private static function filled(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
