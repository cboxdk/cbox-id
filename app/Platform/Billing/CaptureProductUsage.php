<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use Cbox\Id\Kernel\Events\EventDelivered;
use Cbox\Id\Kernel\Usage\EventMetricMap;
use Illuminate\Support\Facades\DB;

/** Copies durable identity events into a product outbox; no billing networking in identity requests. */
final class CaptureProductUsage
{
    public function handle(EventDelivered $delivered): void
    {
        $event = $delivered->event;
        $metric = EventMetricMap::for($event->type);
        if ($metric === null || $event->environment_id === null) {
            return;
        }
        $workspace = DB::table('environments')->join('projects', 'projects.id', '=', 'environments.project_id')
            ->where('environments.id', $event->environment_id)->value('projects.organization_id');
        // Root identity traffic and operator infrastructure have no customer project.
        if (! is_string($workspace) || $workspace === '') {
            return;
        }
        DB::table('product_billing_usage_outbox')->insertOrIgnore([
            'event_id' => $event->id, 'workspace_id' => $workspace,
            'meter' => 'cbox-id.'.$metric->value, 'occurred_at' => $event->occurred_at,
        ]);
    }
}
