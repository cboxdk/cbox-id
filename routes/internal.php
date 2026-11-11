<?php

declare(strict_types=1);

use App\Http\Controllers\Api\OnboardingEligibilityController;
use App\Http\Middleware\InClusterOnly;
use App\Platform\Api\RouteMirror;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/*
 * THE PATHS THAT MOVE ON THE APPLICATION HOST (plan §2.2), served next to the old ones
 * until their callers have moved:
 *
 *  - `/internal/v1/*`: health, metrics, queue metrics and Engage's onboarding check, for
 *    callers inside the cluster only ({@see InClusterOnly}; the tunnel blocks `/internal/`
 *    with the contract step). Next to `/health/*`, `/telemetry/metrics`, `/queue-metrics/*`
 *    and `/api/v1/onboarding/eligibility`.
 *  - `/webhooks/*`: the inbound webhooks, next to `/postal/webhook`, `/postal/inbound` and
 *    `/ai-assistant/hook`.
 *
 * Each is a copy of the original route — same handler, same middleware — read from the
 * router after every package has registered its own.
 */
$router = app(Router::class);

Route::middleware(['api', 'internal', 'throttle:120,1'])
    ->post('internal/v1/onboarding/eligibility', OnboardingEligibilityController::class)
    ->name('internal.onboarding.eligibility');

RouteMirror::mirror($router, 'health.liveness', 'internal/v1/health', 'internal.health.liveness', ['internal']);
RouteMirror::mirror($router, 'health.readiness', 'internal/v1/health/ready', 'internal.health.readiness', ['internal']);
RouteMirror::mirror($router, 'health.startup', 'internal/v1/health/startup', 'internal.health.startup', ['internal']);
RouteMirror::mirror($router, 'health.status', 'internal/v1/health/status', 'internal.health.status', ['internal']);
RouteMirror::mirror($router, 'health.metrics', 'internal/v1/health/metrics', 'internal.health.metrics', ['internal']);
RouteMirror::mirror($router, 'telemetry.prometheus.default', 'internal/v1/metrics', 'internal.metrics', ['internal']);
RouteMirror::mirrorAll($router, 'queue-metrics.', 'queue-metrics', 'internal/v1/queue-metrics', 'internal.queue-metrics.', ['internal']);

// Inbound first, so `/webhooks/postal/inbound` is never read as a server named "inbound".
RouteMirror::mirror($router, 'postal.inbound', 'webhooks/postal/inbound/{server?}', 'webhooks.postal.inbound');
RouteMirror::mirror($router, 'postal.webhook', 'webhooks/postal/{server?}', 'webhooks.postal')?->where('server', '(?!inbound$)[^/]+');
RouteMirror::mirror($router, 'ai-assistant-connector.hook', 'webhooks/cbox-assistant', 'webhooks.cbox-assistant');
