---
title: Queue workers
weight: 5
description: Running the queue manager on Kubernetes, on a VM or on a PaaS, the operator-only job monitor, and the health signal that goes red when nothing is processing the queue.
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
with the reason for each value beside it. The defaults are sized for the smallest shape
this runs on, a 512 MB host that also serves the web traffic; the production cluster
changes the first row (below):

| Setting | Value | Why |
|---|---|---|
| Mode | single host (`QUEUE_AUTOSCALE_CLUSTER_ENABLED=false`); **cluster** in production | One host, one manager. Cluster mode elects a leader over Redis/Valkey, for when more than one manager can run. |
| Workers per group | min 1, max 2 (`QUEUE_AUTOSCALE_WORKERS_MIN` / `QUEUE_AUTOSCALE_WORKERS_MAX`) | Never scale to zero: a cold start on every sign-out is latency for nothing. Raise the max with the total cap. |
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

## Kubernetes

Production (`cboxid.com`) runs the manager in a **worker** Deployment of its own: one pod,
the web pods' image and environment, with nginx and PHP-FPM switched off and `cbox-init`
running two processes in it — `php artisan queue:autoscale` and the scheduler
(`php artisan schedule:work`, which also runs the monitor's retention, below):

```yaml
env:
  - {name: CBOX_INIT_PROCESS_PHP_FPM_ENABLED, value: "false"}
  - {name: CBOX_INIT_PROCESS_NGINX_ENABLED, value: "false"}
  - {name: CBOX_INIT_PROCESS_SCHEDULER_ENABLED, value: "true"}
  - {name: CBOX_INIT_PROCESS_AUTOSCALER_ENABLED, value: "true"}
  - {name: CBOX_INIT_PROCESS_AUTOSCALER_USER, value: www-data}
  - {name: CBOX_INIT_PROCESS_SCHEDULER_USER, value: www-data}
  - {name: CBOX_INIT_PROCESS_AUTOSCALER_SHUTDOWN_TIMEOUT, value: "80"}
  - {name: CBOX_INIT_GLOBAL_SHUTDOWN_TIMEOUT, value: "80"}
```

`cbox-init` restarts either process if it exits. The workers the manager starts live
inside that pod, so the web pods' memory is not what they compete for.

**Deploys: nothing to add.** A rollout replaces the pod — `Recreate`, not overlapped, so
there is one scheduler at a time: Kubernetes sends the outgoing pod SIGTERM, the manager
lets its workers finish their current job within the 80-second shutdown timeout (inside a
90-second termination grace), and the new pod starts a manager on the new code.
`php artisan queue:autoscale:restart` would only restart the outgoing manager on the
outgoing release.

What the environment sets, and why:

- `QUEUE_CONNECTION=redis` and `CACHE_STORE=redis`, both on the namespace's Valkey. The
  manager and the web tier meet in the cache: the heartbeat, the failure fuse and the
  restart signal all live there.
- `QUEUE_AUTOSCALE_CLUSTER_ENABLED=true`. One manager runs, but in cluster mode a second —
  a replica added later, or one started by hand in a pod — elects a leader over Valkey
  with it instead of each sizing workers as if alone.
- The Valkey runs **`maxmemory-policy noeviction`**. A queued job is data: under an
  evicting policy a full instance silently deletes webhooks and mail.
- Queue metrics use Redis by default (`QUEUE_METRICS_STORAGE=redis`); leave it.

On another cluster, run the same thing: either this one worker pod, or the manager and the
scheduler as two Deployments of one replica each (the local `cbox.yaml` does the latter,
as `queue` and `scheduler` processes).

**Sizing.** Two workers is a ceiling chosen for a 512 MB host shared with the web tier,
and the worker pod shares with nobody. There are two ceilings, and you raise them together:

- `QUEUE_AUTOSCALE_MAX_TOTAL_WORKERS`, the hard cap for the whole host (pod).
- `QUEUE_AUTOSCALE_WORKERS_MAX`, the most workers one group runs. Every queue this app
  dispatches to on one connection shares one group, so this is the number that actually
  decides how many workers run. Raising the total cap alone changes nothing.

`QUEUE_AUTOSCALE_WORKERS_MIN` sets the floor (default 1, never below 1). For example, a
worker pod with a 1 GiB limit, in the environment it reads:

```yaml
env:
  QUEUE_AUTOSCALE_MAX_TOTAL_WORKERS: "6"
  QUEUE_AUTOSCALE_WORKERS_MAX: "6"
```

The app checks the pair when its configuration loads and refuses to start, naming the
variable, if `WORKERS_MAX` is below `WORKERS_MIN` or above the total cap (a group maximum
the cap would silently clamp). The web pods read the same configuration, so a bad pair stops
the rollout at its readiness probe instead of shipping a setting that does nothing.

Budget about 96 MB per worker plus the manager's own 75 MB against the pod's memory limit.
Keep a hard cap even then: it holds when a container reports the node's memory rather than
its own limit, which makes `max_memory_percent` meaningless.

## On a PaaS

A platform that runs long-lived processes beside the web ("background process", "worker
process", a Procfile `worker:` line) runs the manager the same way: one process,
`php artisan queue:autoscale`, restarted if it exits. Two things to check:

- **Do not also add the platform's own queue-worker type.** The manager is the worker; two
  supervisors fight over the same jobs.
- **Find out whether it runs one per instance.** Many platforms start a background process
  on every instance of the app. If the app scales past one, either move the manager to a
  worker group with exactly one instance, or set `QUEUE_AUTOSCALE_CLUSTER_ENABLED=true` so
  the managers elect a leader and share the work.

If the platform replaces instances on deploy and sends SIGTERM first, there is no deploy
step to add; if it restarts processes in place, keep `php artisan queue:restart` in the
deploy, as below.

## On a VM (systemd or Supervisor)

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
Kubernetes, check the worker pod is running and read its log; on a PaaS, the background
process.

**The manager runs, but jobs still wait.** `php artisan queue:autoscale:debug
--queue=default --connection=redis` shows the metrics it sees and the fuse state. An open
fuse means most recent jobs failed; the monitor shows why.

**`Failed to spawn worker` in the log.** Run `php artisan queue:work redis --queue=default`
by hand as the same user; the error is usually obvious.

**Two managers on one host.** The second exits with a lock error. Use
`php artisan queue:autoscale --replace` to take over from a stuck one.
