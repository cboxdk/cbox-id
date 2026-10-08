<?php

use App\Models\RiskDecision;
use App\Platform\Actions\Idempotency\IdempotencyRecord;
use App\Platform\FrontendApi\LoginTicket;
use App\Platform\Health\SchedulerHeartbeat;
use Cbox\Id\Analytics\Models\AnalyticsEvent;
use Cbox\Id\Devices\Models\EnrolmentCode;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Bound the adaptive-risk decision trail. The framework's own `cbox-id:prune` sweeps
// only the tables named in its internal `PrunableTable` enum, which an application
// cannot extend — so this app-owned table is swept by Laravel's `model:prune`, whose
// window comes from `cbox-id.risk_trail.retention_days` ({@see RiskDecision::prunable()}).
// Named explicitly: a bare `model:prune` discovers every prunable model and would
// silently start sweeping a future one nobody meant to schedule here.
// AnalyticsEvent joins it for the same reason, and with more at stake: it is the one
// table that grows with TRAFFIC rather than with tenants, so without this sweep the
// relational analytics store grows without bound. Its window is
// `id-analytics.retention_days`. Harmless when the store is off — the table is simply
// empty. ({@see \Cbox\Id\Analytics\Models\AnalyticsEvent::prunable()})
// LoginTicket is the third, and the one that grows fastest: a row per embedded sign-in
// attempt, each naming a subject. Its window is fixed rather than configurable — a ticket
// lives sixty seconds and the row is kept an hour past that, which is long enough to look
// at during an incident and short enough that nobody has to think about it.
// ({@see \App\Platform\FrontendApi\LoginTicket::prunable()})
// EnrolmentCode is the fourth: a row per handset enrolled, kept only to refuse a replay
// of a code that lives two minutes. Swept an hour past its own expiry, and its
// prunable() deliberately drops the environment scope — that scope is deny-by-default,
// and a scheduled sweep has no environment in context, so left alone it would delete
// nothing while appearing to work.
// ({@see \Cbox\Id\Devices\Models\EnrolmentCode::prunable()})
// IdempotencyRecord is the fifth: the first answer to an `Idempotency-Key` request, kept a
// day so a retry gets it back, and useless after that.
// ({@see \App\Platform\Actions\Idempotency\IdempotencyRecord::prunable()})
Schedule::command('model:prune', ['--model' => [RiskDecision::class, AnalyticsEvent::class, LoginTicket::class, EnrolmentCode::class, IdempotencyRecord::class]])
    ->daily()
    ->onOneServer();

// Audit logs — the events an app sends about its own customers — kept for each
// environment's retention (`cbox-id.audit_logs.retention_days` unless the environment set
// its own) and cut from the front of each organization's chain, which `model:prune` could
// not do: the cut has to be recorded on the chain before the rows go. Expired export files
// are deleted by the same pass. ({@see \App\Platform\AuditLogs\AuditLogPruner})
Schedule::command('audit-logs:prune')
    ->daily()
    ->onOneServer();

// The queue monitor's retention — without these the package's retention settings are
// only settings. `prune` keeps a week (and at most `retention.max_rows`) of job history
// AND the autoscaler's scaling and cluster events, which the manager writes every cycle;
// `resolve-stuck` closes out rows a killed worker left `processing` forever, which would
// otherwise read on the dashboard as work in flight. See config/queue-monitor.php.
Schedule::command('queue-monitor:prune')
    ->daily()
    ->onOneServer();

Schedule::command('queue-monitor:resolve-stuck')
    ->everyFifteenMinutes()
    ->onOneServer();

// The scheduler's own heartbeat, read by `scheduler` on /health/status and by the doctor.
// Every background duty here is a scheduled command, so a deployment without
// `schedule:work` does none of them and logs nothing; this beat is what makes that
// visible. NOT onOneServer(): it proves THIS scheduler is alive, and a second one
// beating the same key is harmless. ({@see \App\Platform\Health\SchedulerHeartbeat})
Schedule::call(static fn () => SchedulerHeartbeat::beat())
    ->name('health:scheduler-heartbeat')
    ->everyMinute();

// SAML signing certificates about to expire: a `connection.certificate_expiring` webhook,
// a trail entry and a mail to the organization's admins at 30 and 7 days, once each.
// Daily is enough — the thresholds are days — and onOneServer() keeps the alerts single.
// ({@see \App\Platform\Sso\CertificateExpiryAlerts})
Schedule::command('cbox-id:sso:certificate-expiry')
    ->dailyAt('06:00')
    ->onOneServer();
