<?php

declare(strict_types=1);

namespace App\Platform\Health;

use Cbox\Id\Console\Contracts\HealthCheck;
use Cbox\Id\Console\ValueObjects\HealthResult;

/**
 * `cbox-id:doctor`: the production settings whose defaults are wrong for production.
 *
 * Every one of these defaults is right for `composer run dev` and silently wrong in a
 * container: mail goes to a log file nobody reads, so no magic link, invitation or
 * verification ever arrives; a per-pod cache gives every replica its own rate limits and
 * replay guards; logs written to a file vanish with the pod. Nothing errors in any of
 * these cases, so the doctor is where they are said out loud.
 *
 * SHARED STATE IS A QUESTION OF HOW MANY. A cache or session store that lives in one
 * process (`array`), on one pod's disk (`file`) or in one PHP-FPM pool's memory (`apc`) is
 * merely a smell on a single replica — and a fault the moment there are two: every replica
 * gets its own rate-limit buckets, its own single-use handoff and magic-link tokens, its own
 * replay guards, and a session that exists on one pod and not the next. So it FAILS when the
 * deployment declares more than one web replica (`cbox-id.deployment.replicas`, set by the
 * manifest beside `replicas:`) or runs the queue manager as a cluster
 * (`queue-autoscale.cluster.enabled`, which coordinates through the cache), and WARNS on one.
 *
 * Outside production it reports nothing to fix: a developer's laptop is supposed to look
 * like this.
 */
class ProductionConfigDoctorCheck implements HealthCheck
{
    /** Stores that live inside one process, one pod's disk or one PHP-FPM pool's memory. */
    private const array LOCAL_STORES = ['array', 'file', 'apc'];

    /** Log drivers that write to the container's own disk. */
    private const array FILE_LOG_DRIVERS = ['single', 'daily'];

    public function run(): array
    {
        if (! app()->isProduction()) {
            return [HealthResult::ok('Production settings', 'Not production: mail, cache and log defaults are fine here.')];
        }

        return [
            $this->mail(),
            $this->cache(),
            $this->session(),
            $this->queue(),
            $this->log(),
            $this->healthToken(),
        ];
    }

    private function mail(): HealthResult
    {
        $mailer = $this->string('mail.default');
        $transport = $this->string("mail.mailers.{$mailer}.transport") ?? $mailer;

        return in_array($transport, ['log', 'array'], true)
            ? HealthResult::fail(
                'Mail is not sent',
                "MAIL_MAILER uses the `{$transport}` transport, so magic links, invitations, password resets and "
                .'verification mail never leave the server. Configure a real mailer.',
            )
            : HealthResult::ok('Mail', "sent with `{$transport}`");
    }

    private function cache(): HealthResult
    {
        $store = $this->string('cache.default');
        $driver = $this->string("cache.stores.{$store}.driver") ?? $store;

        if (! in_array($driver, self::LOCAL_STORES, true)) {
            return HealthResult::ok('Cache', "shared `{$driver}` store");
        }

        $detail = "CACHE_STORE uses `{$driver}`. Rate limits, single-use handoff tokens and replay guards then hold per "
            .'process, not per deployment';

        return $this->scaledOut() !== null
            ? HealthResult::fail('Cache is local to one process', "{$detail} — and this deployment {$this->scaledOut()}. Use redis.")
            : HealthResult::warn('Cache is local to one process', "{$detail}. Fine for exactly one replica; use redis before adding a second.");
    }

    private function session(): HealthResult
    {
        $driver = $this->string('session.driver');

        if (! in_array($driver, self::LOCAL_STORES, true)) {
            return HealthResult::ok('Sessions', "`{$driver}`");
        }

        $detail = "SESSION_DRIVER is `{$driver}`, so a person is signed out whenever a request lands on another replica "
            .'or the pod restarts';

        return $this->scaledOut() !== null
            ? HealthResult::fail('Sessions are local to one process', "{$detail} — and this deployment {$this->scaledOut()}. Use redis or database.")
            : HealthResult::warn('Sessions are local to one process', "{$detail}. Use redis or database.");
    }

    /**
     * Why this deployment is more than one process sharing nothing, or null when it is one
     * web replica with an unclustered queue manager.
     */
    private function scaledOut(): ?string
    {
        $replicas = config('cbox-id.deployment.replicas', 1);
        $replicas = is_numeric($replicas) ? (int) $replicas : 1;

        if ($replicas > 1) {
            return "declares {$replicas} web replicas (CBOX_ID_REPLICAS)";
        }

        return filter_var(config('queue-autoscale.cluster.enabled', false), FILTER_VALIDATE_BOOLEAN)
            ? 'runs the queue manager as a cluster (QUEUE_AUTOSCALE_CLUSTER_ENABLED)'
            : null;
    }

    private function queue(): HealthResult
    {
        $connection = $this->string('queue.default');
        $driver = $this->string("queue.connections.{$connection}.driver") ?? $connection;

        return $driver === 'sync'
            ? HealthResult::warn(
                'Queue runs inline',
                'QUEUE_CONNECTION is `sync`: webhooks, back-channel logout and mail are sent during the request that '
                .'caused them, and a slow receiver slows the person down. Use redis or database with a queue manager.',
            )
            : HealthResult::ok('Queue', "`{$driver}`");
    }

    private function log(): HealthResult
    {
        $drivers = $this->logDrivers($this->string('logging.default'));
        $onDisk = $drivers !== [] && array_diff($drivers, self::FILE_LOG_DRIVERS) === [];

        return $onDisk
            ? HealthResult::warn(
                'Logs are written to the local disk',
                'Every log channel writes a file inside the instance, which disappears with a container. '
                .'Set LOG_CHANNEL=stderr (or ship the files) so incidents leave a trace.',
            )
            : HealthResult::ok('Logs', 'written to '.implode(', ', $drivers === [] ? ['an unknown channel'] : $drivers));
    }

    private function healthToken(): HealthResult
    {
        return $this->string('health.security.token') === null
            ? HealthResult::fail(
                'No health token',
                'HEALTH_TOKEN is not set, so /health/ready and /health/status answer 403 to the platform\'s own '
                .'probe and to monitoring. Set it and pass it as ?token=.',
            )
            : HealthResult::ok('Health token', 'set');
    }

    /**
     * The drivers a log channel finally writes with, following `stack` channels.
     *
     * @return list<string>
     */
    private function logDrivers(?string $channel, int $depth = 0): array
    {
        if ($channel === null || $depth > 5) {
            return [];
        }

        $driver = $this->string("logging.channels.{$channel}.driver");

        if ($driver !== 'stack') {
            return $driver === null ? [] : [$driver];
        }

        $children = config("logging.channels.{$channel}.channels", []);
        $drivers = [];

        foreach (is_array($children) ? $children : [] as $child) {
            if (is_string($child)) {
                $drivers = [...$drivers, ...$this->logDrivers($child, $depth + 1)];
            }
        }

        return array_values(array_unique($drivers));
    }

    private function string(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
