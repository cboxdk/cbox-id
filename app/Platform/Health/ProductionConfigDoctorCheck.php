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
 * Outside production it reports nothing to fix: a developer's laptop is supposed to look
 * like this.
 */
class ProductionConfigDoctorCheck implements HealthCheck
{
    /** Stores that live inside one process or one pod's disk. */
    private const array LOCAL_STORES = ['array', 'file'];

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

        return in_array($driver, self::LOCAL_STORES, true)
            ? HealthResult::fail(
                'Cache is local to one process',
                "CACHE_STORE uses `{$driver}`. Rate limits, single-use handoff tokens and replay guards then hold per "
                .'process, not per deployment. Use redis or database.',
            )
            : HealthResult::ok('Cache', "shared `{$driver}` store");
    }

    private function session(): HealthResult
    {
        $driver = $this->string('session.driver');

        return in_array($driver, self::LOCAL_STORES, true)
            ? HealthResult::fail(
                'Sessions are local to one process',
                "SESSION_DRIVER is `{$driver}`, so a person is signed out whenever a request lands on another replica "
                .'or the pod restarts. Use redis or database.',
            )
            : HealthResult::ok('Sessions', "`{$driver}`");
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
