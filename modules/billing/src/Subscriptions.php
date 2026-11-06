<?php

declare(strict_types=1);

namespace Cbox\Id\Billing;

use Cbox\Billing\Client\BillingManagement;
use Cbox\Id\Billing\Contracts\ProjectPayments;
use Cbox\Id\Billing\Models\ProjectSubscription;
use Cbox\Id\Platform\Models\Project;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class Subscriptions
{
    public function __construct(private ProjectPayments $payments, private PlanCatalog $catalog, private BillingManagement $billing) {}

    public function refresh(Project $project): void
    {
        $data = $this->payments->subscription($project->organization_id, $project->id);
        // An absent subscription is not proof that a serving grant has ended.
        if ($data !== null) {
            $this->apply($project, is_array($data['subscription'] ?? null) ? array_filter($data['subscription'], is_string(...), ARRAY_FILTER_USE_KEY) : $data);
        }
    }

    /** @param array<string, mixed> $data */
    public function apply(Project $project, array $data, ?DateTimeImmutable $updatedAt = null): void
    {
        $plan = $data['plan'] ?? null;
        $status = $data['status'] ?? null;
        if (! is_string($plan) || ! is_string($status) || ! in_array($status, ['active', 'trialing', 'past_due', 'canceled', 'cancelled', 'ended', 'expired', 'unpaid', 'paused'], true)) {
            throw new RuntimeException('Subscription response does not name a known plan and status.');
        }
        $limit = $this->catalog->environmentLimit($plan);
        DB::transaction(function () use ($project, $plan, $status, $data, $limit, $updatedAt): void {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $current = ProjectSubscription::query()->find($project->id);
            if ($updatedAt !== null && $current?->remote_updated_at !== null && $current->remote_updated_at > $updatedAt) {
                return;
            }
            ProjectSubscription::query()->updateOrCreate(['project_id' => $project->id], [
                'organization_id' => $project->organization_id, 'plan' => $plan, 'status' => $status, 'data' => $data,
                'remote_updated_at' => $updatedAt ?? now(),
            ]);
            // Ended is an explicit remote decision. Outages, missing rows and unknown
            // plans never revoke an existing grant. Existing environments are not deleted.
            if (in_array($status, ['canceled', 'cancelled', 'ended', 'expired'], true)) {
                $locked->environment_limit = 0;
            } elseif (in_array($status, ['active', 'trialing'], true) && ($data['paused'] ?? false) !== true) {
                $locked->environment_limit = $limit;
            }
            $locked->save();
        });
    }

    public function sync(): int
    {
        $start = now()->toImmutable();
        $watermark = DB::table('id_billing_sync')->where('key', 'subscriptions')->value('watermark');
        $since = is_string($watermark) ? new DateTimeImmutable($watermark) : null;
        $count = 0;
        foreach ($this->billing->allSubscriptions($since, 100) as $change) {
            if ($change->product !== config('billing.product') || $change->reference === null) {
                continue;
            }
            $project = Project::query()->whereKey($change->reference)->where('organization_id', $change->organizationKey())->first();
            if ($project === null) {
                continue;
            }
            $this->apply($project, [
                'plan' => $change->plan, 'status' => $change->status, 'paused' => $change->paused,
                'period_end' => $change->periodEnd?->format(DATE_ATOM), 'cancel_at_period_end' => $change->cancelAtPeriodEnd,
            ], $change->updatedAt);
            $count++;
        }
        // Only advance after all pages succeed; inclusive replay is safe.
        DB::table('id_billing_sync')->updateOrInsert(['key' => 'subscriptions'], ['watermark' => $start]);

        return $count;
    }
}
