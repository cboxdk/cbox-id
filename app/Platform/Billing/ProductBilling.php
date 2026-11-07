<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Platform\Actions\ActionRefused;
use Cbox\Billing\Client\BillingClient;
use Cbox\Billing\Client\Enums\FeatureType;
use Cbox\Billing\Client\ValueObjects\FeatureEntitlements;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Hosted product commerce only; never replaces the identity platform's entitlement reader. */
final class ProductBilling
{
    public function __construct(private readonly BillingClient $billing, private readonly PlatformRoot $root) {}

    public function features(string $workspaceId): FeatureEntitlements
    {
        if ($workspaceId === '') {
            throw new ActionRefused('billing_unavailable', __('billing.unavailable'), 503);
        }
        try {
            $features = $this->billing->features($workspaceId);
            if ($features->org !== $workspaceId) {
                throw new \UnexpectedValueException('The billing account did not match the workspace.');
            }

            return $features;
        } catch (Throwable $error) {
            report($error);
            throw new ActionRefused('billing_unavailable', __('billing.unavailable'), 503);
        }
    }

    /** Nested directory organizations consume their owning workspace's product grant. */
    public function owningWorkspace(string $organizationId): ?string
    {
        $environmentId = DB::table('organizations')->where('id', $organizationId)->value('environment_id');
        if (! is_string($environmentId)) {
            return null;
        }
        if ($environmentId === $this->root->model()?->id) {
            return $organizationId;
        }
        $workspace = DB::table('environments')->join('projects', 'projects.id', '=', 'environments.project_id')
            ->where('environments.id', $environmentId)->value('projects.organization_id');

        return is_string($workspace) && $workspace !== '' ? $workspace : null;
    }

    public function enabled(string $organizationId, string $feature): bool
    {
        if (! in_array($feature, ['sso', 'scim', 'audit_logs'], true)) {
            return false;
        }
        $request = request();
        $memoize = $request->route() instanceof Route && $request->isMethodSafe();
        $workspaceKey = self::class.':workspace:'.$organizationId;
        $workspace = $memoize && $request->attributes->has($workspaceKey)
            ? $request->attributes->get($workspaceKey) : $this->owningWorkspace($organizationId);
        if ($memoize) {
            $request->attributes->set($workspaceKey, $workspace);
        }
        if (! is_string($workspace) || $workspace === '') {
            return false;
        }
        try {
            $featuresKey = self::class.':features:'.$workspace;
            $features = $memoize ? $request->attributes->get($featuresKey) : null;
            if (! $features instanceof FeatureEntitlements) {
                $features = $this->features($workspace);
                if ($memoize) {
                    $request->attributes->set($featuresKey, $features);
                }
            }

            return $features->enabled('cbox-id.'.$feature);
        } catch (ActionRefused) {
            return false;
        }
    }

    /** Maximum per project, assigned manually in Billing. Missing/suspended/disabled means zero. */
    public function environmentLimit(string $workspaceId): int
    {
        $snapshot = $this->features($workspaceId);
        $feature = $snapshot->for('cbox-id.environments');
        if (! $snapshot->enabled('cbox-id.environments') || $feature?->type !== FeatureType::Config) {
            return 0;
        }

        return is_int($feature->value) && $feature->value >= 0 ? $feature->value : 0;
    }

    public function projectLimit(Project $project): int
    {
        return min($project->environment_limit, $this->environmentLimit($project->organization_id));
    }

    /** Caller holds the project row lock until provisioning commits. */
    public function assertEnvironmentCapacity(string $workspaceId, Project $project): void
    {
        if ($project->organization_id !== $workspaceId) {
            throw ActionRefused::notFound('project');
        }
        if (Environment::query()->where('project_id', $project->id)->count() >= $this->projectLimit($project)) {
            throw ActionRefused::because('environment_limit_reached', __('billing.allowance_reached'), 'name');
        }
    }
}
