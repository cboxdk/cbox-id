<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Cbox\LaravelHealth\Enums\EndpointType;
use Cbox\LaravelHealth\Enums\Status;
use Cbox\LaravelHealth\Services\HealthCheckRunner;
use Cbox\LaravelHealth\Services\SystemMetricsService;
use Illuminate\Http\JsonResponse;

/**
 * `GET /health/status` — the whole deployment's health, for ALERTING, never for routing.
 *
 * The package's own status endpoint runs liveness and readiness and nothing else, and
 * answers 200 whatever it finds. This one adds the `status` check list
 * (`health.checks.status` — the queue workers) under `operations`, and answers 503 when
 * anything is critical, so an uptime monitor can alert on the status code alone.
 *
 * The split is the point. `/up` and `/health/ready` say whether THIS instance can serve
 * web traffic, and load balancers act on them. What is on this endpoint and not on those —
 * a dead queue manager, a queue behind its SLA — is real trouble that no web instance can
 * fix by being taken out of rotation, so it goes where a person is told rather than where
 * traffic is moved. Route on `/up` and `/health/ready`; alert on `/health/status`.
 *
 * Behind the same token as readiness (`EndpointAuth:status`), because the body names
 * which dependency is unhappy.
 */
final class HealthStatusController
{
    public function __invoke(HealthCheckRunner $runner, SystemMetricsService $metrics): JsonResponse
    {
        $liveness = $runner->run(EndpointType::Liveness);
        $readiness = $runner->run(EndpointType::Readiness);
        $operations = $runner->run(EndpointType::Status);

        $status = Status::worst([$liveness->status, $readiness->status, $operations->status]);

        return new JsonResponse([
            'status' => $status->value,
            'liveness' => $liveness->toArray(),
            'readiness' => $readiness->toArray(),
            'operations' => $operations->toArray(),
            'system' => $metrics->collect(),
            'app' => [
                'name' => config('app.name'),
                'environment' => app()->environment(),
                'hostname' => gethostname() ?: null,
            ],
        ], $status->isHealthy() ? 200 : 503);
    }
}
