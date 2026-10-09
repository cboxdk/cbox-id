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
 * FILES ARE THE SAME QUESTION, asked of the disks one process writes and another reads. An
 * audit-log export is written by a queue worker and downloaded through a web replica; a brand
 * logo is uploaded through one web replica and fetched through all of them. On one server
 * that is one disk. On Kubernetes it is a disk per pod that disappears with the pod — the
 * worker's CSV is not on the web pod that streams the download, and a logo uploaded on one
 * replica 404s on the other — so a `local` disk for any of them FAILS once the deployment is
 * scaled out. And a brand asset on a local disk is only reachable through the `public/storage`
 * link, which `php artisan storage:link` makes and the production image does not have.
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
            $this->files(),
            $this->brandAssetLinks(),
        ];
    }

    /**
     * Every disk one process writes and another reads, by what it holds.
     *
     * @return array<string, string> disk name keyed by what is on it
     */
    private function sharedDisks(): array
    {
        $disks = [
            'audit-log exports (CBOX_ID_AUDIT_LOGS_EXPORT_DISK: written by a queue worker, downloaded through a web replica)' => $this->string('cbox-id.audit_logs.export_disk') ?? 'local',
            'brand assets (WHITELABEL_ASSETS_DISK: uploaded through one web replica, served by all of them)' => $this->string('whitelabel.assets.disk') ?? 'public',
        ];

        if ($this->string('compliance.export.sink') === 'jsonl') {
            $disks['the compliance archive (CBOX_ID_COMPLIANCE_JSONL_DISK: appended by a queue worker)'] = $this->string('compliance.export.jsonl.disk') ?? 'local';
        }

        return $disks;
    }

    private function files(): HealthResult
    {
        $local = [];

        foreach ($this->sharedDisks() as $what => $disk) {
            if ($this->diskDriver($disk) === 'local') {
                $local[] = "{$what} on `{$disk}`";
            }
        }

        if ($local === []) {
            return HealthResult::ok('Shared files', 'exports and brand assets are on shared storage');
        }

        $detail = 'These live on the local disk of whichever process wrote them: '.implode('; ', $local);

        return $this->scaledOut() !== null
            ? HealthResult::fail(
                'Files are local to one pod',
                "{$detail}. This deployment {$this->scaledOut()}, so a download or a logo is missing on every pod but the one "
                .'that wrote it, and gone when that pod is replaced. Point these disks at shared storage (an s3 disk).',
            )
            : HealthResult::ok('Shared files', "{$detail} — one server, one disk. Move them to shared storage before adding a second.");
    }

    /**
     * A brand asset on a local disk is served from `public/storage`, the link `storage:link`
     * makes. Without it every uploaded logo and favicon is a dead link on the sign-in page.
     */
    private function brandAssetLinks(): HealthResult
    {
        $disk = $this->string('whitelabel.assets.disk') ?? 'public';
        $url = $this->string("filesystems.disks.{$disk}.url") ?? '';

        if ($this->diskDriver($disk) !== 'local' || ! str_contains($url, '/storage')) {
            return HealthResult::ok('Brand asset links', "served by the `{$disk}` disk itself");
        }

        return is_dir(public_path('storage'))
            ? HealthResult::ok('Brand asset links', 'public/storage is linked')
            : HealthResult::fail(
                'Brand asset links are dead',
                "Brand assets are written to the local `{$disk}` disk and linked as {$url}/…, but public/storage does not exist, "
                .'so every uploaded logo and favicon answers 404. Run `php artisan storage:link` where the files are written, or '
                .'point WHITELABEL_ASSETS_DISK at shared storage with its own URL.',
            );
    }

    private function diskDriver(string $disk): ?string
    {
        return $this->string("filesystems.disks.{$disk}.driver");
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
