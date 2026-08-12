<?php

use App\Models\RiskDecision;
use App\Platform\FrontendApi\LoginTicket;
use Cbox\Id\Analytics\Models\AnalyticsEvent;
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
Schedule::command('model:prune', ['--model' => [RiskDecision::class, AnalyticsEvent::class, LoginTicket::class]])
    ->daily()
    ->onOneServer();
