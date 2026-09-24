---
title: Queue workers
weight: 5
description: Running the queue manager on Laravel Cloud and self-hosted, the operator-only job monitor, and the health signal that goes red when nothing is processing the queue.
---

# Queue workers

**Run exactly one `php artisan queue:autoscale` per host, always.** Without it, nothing
queued is ever sent: no webhook, no back-channel logout to the apps somebody signed out
of, no synced app roles, no Postal delivery report, no queued email. The web tier keeps
answering 200 the whole time, so nothing else tells you.

## What runs on the queue

Everything below is dispatched to a queue and done by a worker, never while the person
waits:

| Work | Job | Queue setting |
|---|---|---|
| Webhook deliveries | `DeliverWebhook` | `CBOX_ID_WEBHOOKS_QUEUE_CONNECTION` / `CBOX_ID_WEBHOOKS_QUEUE` |
| Back-channel logout tokens | `DeliverBackchannelLogout` | the default queue |
| App role manifests pulled from apps | `SyncAppManifestJob` | the default queue |
| Outbound user sync and audit streaming | `DrainProvisioningConnection`, `PumpAuditStream`, `PumpStreamDeliveries` | the default queue (`SIEM_QUEUE*` for the last) |
| Push notifications to trusted devices | `DeliverPushNotification` | `CBOX_ID_DEVICES_QUEUE_CONNECTION` / `CBOX_ID_DEVICES_QUEUE` |
| Postal webhooks and inbound mail | `ProcessWebhook`, `ProcessInboundMessage` | `POSTAL_WEBHOOK_*`, `POSTAL_INBOUND_*` |
| Queued mail, notifications and listeners | the framework's own | the default queue |

A blank setting means "the default queue of the default connection" (`QUEUE_CONNECTION`
and its `REDIS_QUEUE`/`DB_QUEUE`, normally `default`). Out of the box every job lands on
one queue: `redis:default` in production.

You do not list these anywhere. `config/queue-autoscale.php` derives the set from the
same settings the dispatchers read (`App\Platform\Queues\DispatchedQueues`), and gives
each connection one worker group that polls all of its queues, default queue first. Move
webhooks to `CBOX_ID_WEBHOOKS_QUEUE=hooks` and the workers follow on the next manager
start.

## The manager

`cboxdk/laravel-queue-autoscale` is the worker supervisor. It is one long-running process
that starts `queue:work` children, sizes them against a pickup SLA, and stops them again.
You never run `queue:work` yourself, and you do not configure a separate queue-worker
process next to it: two supervisors fight over the same jobs.

The settings are in `config/queue-autoscale.php` and `App\Platform\Queues\WorkerProfile`,
with the reason for each value beside it. In short, for a 512 MB instance that also serves
the web traffic:

| Setting | Value | Why |
|---|---|---|
| Mode | single host | One App instance, one manager. |
| Workers per group | min 1, max 2 | Never scale to zero: a cold start on every sign-out is latency for nothing. |
| `limits.max_total_workers` | 2 (`QUEUE_AUTOSCALE_MAX_TOTAL_WORKERS`) | The hard cap that keeps PHP-FPM alive. A worker of this app is about 75 MB resident, the manager about the same. |
| `limits.max_memory_percent` | 70 | Stop spawning while the instance is above 70 % memory. |
| Pickup SLA | 30 s | Also the line the `queue_workers` health check turns red at. |
| Job timeout | 75 s | Must stay below `retry_after` (90 s), or Redis hands a running job to a second worker and a webhook or logout token is sent twice. |
| Failure fuse | on | A relying party that is down gets fewer workers, not more. |

Check the configuration against the queues that exist:

```bash
php artisan queue:autoscale:doctor
```

A healthy production run prints `Discovered queues: 1` and `✓ Nothing to report.` Before
anything has been dispatched it says there is nothing to check yet, which is expected.

`queue:autoscale` needs `ext-pcntl` and `ext-posix`. Composer refuses to install without
them; `php artisan cbox-id:doctor` checks them again in the CLI the manager runs under and
says so in words if either is missing.

## Laravel Cloud

**Background process** (App cluster → Background processes → New background process →
Custom worker):

```bash
php artisan queue:autoscale
```

Processes: **1**. Cloud restarts it if it exits.

**Deploy commands: no change.** Cloud replaces the instance on every deploy and stops the
old one gracefully; the manager catches the SIGTERM, drains its workers and exits, and the
new instance starts a new manager on the new code. `php artisan queue:autoscale:restart`
in a deploy command would only restart the outgoing manager on the outgoing release.

Also required on Cloud:

- `QUEUE_CONNECTION=redis` and `CACHE_STORE=redis` (the manager and the web tier meet in
  the cache: the heartbeat, the failure fuse and the restart signal all live there).
- Queue metrics use Redis by default (`QUEUE_METRICS_STORAGE=redis`); leave it.
- The scheduler turned on (it also runs the monitor's retention, below).
- Do not also add Cloud's own "Queue worker" process type. The manager is the worker.

**Growing past one instance.** Cloud runs a background process on every instance of its
cluster. If the App cluster autoscales to several replicas, each one runs a manager, and
each sizes its workers as if it were alone. Before allowing more than one replica, either
move the manager to a Worker cluster with exactly one instance, or set
`QUEUE_AUTOSCALE_CLUSTER_ENABLED=true` so the managers elect a leader over Redis and share
the work. On a Worker cluster with memory to spare, raise
`QUEUE_AUTOSCALE_MAX_TOTAL_WORKERS`.

## Self-hosted

Run the manager under your process supervisor, one per host. systemd:

```ini
[Unit]
Description=Cbox ID queue manager
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/cbox-id/current
ExecStart=/usr/bin/php artisan queue:autoscale
Restart=always
RestartSec=5s
KillSignal=SIGTERM
TimeoutStopSec=60s

[Install]
WantedBy=multi-user.target
```

`TimeoutStopSec` must be at least the workers' 30 s drain window, so the manager can stop
its workers before systemd kills it. Supervisor works the same way (`stopsignal=TERM`,
`stopwaitsecs=60`).

**On deploy**, after the new release is in place:

```bash
php artisan queue:restart
```

The manager honours Laravel's own restart signal: its workers finish their current job and
exit, the manager exits, and the supervisor starts it again on the new code.
`php artisan queue:autoscale:restart` restarts only the manager.

`docker-compose.yml` already runs the manager as the `queue` service.

The manager needs Redis for its metrics (`QUEUE_METRICS_STORAGE=redis`, the default). A
single-host install without Redis can switch queue metrics to database storage, but that
is not set up in this repository.

## The health signal

`GET /health/status?token=…` (the `HEALTH_TOKEN`, as for readiness) runs a `queue_workers`
check, and answers 503 when it is red. It is red when either is true:

- **No manager has reported in** for `2 × scaling.cooldown_seconds + 4 × interval`
  (140 s by default). The manager beats on every evaluation cycle; the window is long
  enough that a manager holding a scale-down, which says nothing for up to one cooldown,
  never reads as dead.
- **A supervised queue's oldest waiting job is older than its pickup SLA** (30 s). This
  is read from the queue itself, so it catches workers that are running but not getting
  through, and it still works if you run plain `queue:work` with the autoscaler off
  (`QUEUE_AUTOSCALE_ENABLED=false`; the manager half then stands down).

The response carries counts, ages and queue names only, never job contents.

**Route on `/up` and `/health/ready`; alert on `/health/status`.** Readiness answers one
question: can this instance serve web traffic? The queue manager is a separate process (on
Kubernetes a separate pod), and if its death made readiness red, every web instance would
be taken out of the load balancer at once. So `queue_workers` is deliberately not on
`/up` or `/health/ready`; it is on `/health/status`, which nothing routes on. Point uptime
monitoring and alerting there.

One thing to know when a red clears by itself: on Redis, a job released with a delay (a
back-channel logout retry) keeps its original creation time, so for the few seconds
between becoming due and a worker taking it, it can read as older than the SLA. A red that
lasts is real.

`php artisan cbox-id:doctor` reports the same state in words.

## The job monitor

**Platform › Insights › Queues** (`/platform/queues`) shows whether a manager is running
and how far behind each queue is. **Open job monitor** on that page opens the
`cboxdk/laravel-queue-monitor` dashboard at `/platform/queues/monitor`: every job with its
status, duration, retries and failure message, and the autoscaler's scaling decisions.

Who can open it:

- **Platform operators only.** Anyone else signed in gets a 404; a signed-out visitor is
  sent to sign in.
- **On the platform root's host only.** On a customer environment's host, including a
  white-label domain, the address does not exist, for operators too.
- The package's own address (`/queue-monitor`) is not registered.

The dashboard runs Alpine.js, which needs `'unsafe-eval'` and an inline script, so these
routes carry a Content-Security-Policy of their own. Only `script-src` is wider than the
console's; everything else stays same-origin.

**Job payloads are never stored.** A queued job is its constructor arguments: webhook
bodies, and in whatever job is added next, tokens or email addresses. The package would
keep them for replay, and its redaction only covers what its screens show. Here payload
storage is off in `config/queue-monitor.php` (as a literal, not an environment variable),
and the column is blanked on every write in `App\Providers\QueueServiceProvider`, even for
a job that asks for its payload to be kept. The price is replay from the dashboard: retry
failed jobs with `php artisan queue:retry`, which reads Laravel's own `failed_jobs` table.

The monitor still stores failure messages and stack traces. Keep job exceptions free of
secrets, the same rule `failed_jobs` already needs.

**The REST API is off.** If you turn it on (`QUEUE_MONITOR_API_ENABLED=true`), it sits
under `/platform/queues/api` behind the same operator session and host check as the
dashboard.

**Retention** runs on the scheduler:

| Schedule | Command | Keeps |
|---|---|---|
| daily | `queue-monitor:prune` | 7 days of job history, at most 100,000 rows (`QUEUE_MONITOR_MAX_ROWS`), and the autoscaler's scaling events |
| every 15 min | `queue-monitor:resolve-stuck` | marks a job still `processing` after 15 minutes as timed out; a worker died mid-job |

The tables are created by the package's own migrations (`queue_monitor_jobs`,
`queue_monitor_tags`, `queue_monitor_scaling_events`, `queue_monitor_cluster_events`) and
run on SQLite, PostgreSQL and MySQL.

## Troubleshooting

**`/health/status` says no manager has ever reported in.** The manager is not running, or it
runs against a different cache than the web tier (check `CACHE_STORE` on both). On
Cloud, check the background process exists and its log.

**The manager runs, but jobs still wait.** `php artisan queue:autoscale:debug
--queue=default --connection=redis` shows the metrics it sees and the fuse state. An open
fuse means most recent jobs failed; the monitor shows why.

**`Failed to spawn worker` in the log.** Run `php artisan queue:work redis --queue=default`
by hand as the same user; the error is usually obvious.

**Two managers on one host.** The second exits with a lock error. Use
`php artisan queue:autoscale --replace` to take over from a stuck one.
