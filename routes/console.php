<?php

use App\Models\RiskDecision;
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
Schedule::command('model:prune', ['--model' => [RiskDecision::class]])
    ->daily()
    ->onOneServer();
