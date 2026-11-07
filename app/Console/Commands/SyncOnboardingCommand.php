<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Onboarding\IntegrationProgress;
use App\Platform\OrganizationAccess;
use Cbox\Engage\Contracts\ProductEvents;
use Cbox\Engage\Events\ProductEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Enums\ProjectStatus;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Console\Command;

/** Reconciliation does not depend on someone opening the onboarding page. */
final class SyncOnboardingCommand extends Command
{
    protected $signature = 'onboarding:sync';

    protected $description = 'Queue environment-scoped onboarding evidence for Engage; never send mail.';

    public function handle(PlatformRoot $root, EnvironmentContext $context, IntegrationProgress $progress, ProductEvents $events): int
    {
        if (config('onboarding.engage_enabled') !== true || ! config('engage.url') || ! config('engage.key') || config('engage.product') !== 'cbox-id') {
            $this->warn('Onboarding collection is disabled or Engage is not configured for cbox-id.');

            return self::FAILURE;
        }
        $count = 0;
        $root->run(function () use ($context, $progress, $events, &$count): void {
            Environment::query()->whereNotNull('project_id')->chunkById(50, function ($environments) use ($context, $progress, $events, &$count): void {
                foreach ($environments as $environment) {
                    $project = Project::query()->find($environment->project_id);
                    $organization = $project === null ? null : Organization::query()->find($project->organization_id);
                    if (! $environment->status->canServe() || $project === null || $project->status !== ProjectStatus::Active || $organization === null || OrganizationAccess::isRevoked($organization->status)) {
                        continue;
                    }
                    $snapshot = $context->runAs($environment, fn (): array => $progress->read());
                    // A heartbeat carries fresh evidence; retrying the same interval is
                    // deduplicated. It is not an automation-enrollment event.
                    $interval = now()->startOfMinute()->toIso8601String();
                    $props = [
                        'journey_version' => 1,
                        'identity_namespace' => config()->string('onboarding.identity_namespace'),
                        'project_id' => $snapshot['project_id'],
                        'environment_id' => $snapshot['environment_id'],
                        'environment_type' => $snapshot['environment_type'],
                    ];
                    foreach ($snapshot['goals'] as $goal => $done) {
                        $props['goal_'.$goal] = $done;
                    }
                    $events->record(ProductEvent::byCompany(
                        'onboarding.progress_observed',
                        'cbox-id:onboarding:'.hash('sha256', $environment->id.'|'.$interval.'|'.json_encode($props, JSON_THROW_ON_ERROR)),
                        $snapshot['workspace_id'],
                        $props,
                    ));
                    if ($snapshot['goals']['first_sign_in'] ?? false) {
                        $events->record(ProductEvent::byCompany(
                            'onboarding.first_sign_in_observed',
                            'cbox-id:first-sign-in:'.hash('sha256', $environment->id.'|'.$interval),
                            $snapshot['workspace_id'],
                            $props,
                        ));
                    }
                    $count++;
                }
            });
        });
        $this->info("Recorded {$count} environment snapshots for asynchronous delivery; no mail sent.");

        return self::SUCCESS;
    }
}
