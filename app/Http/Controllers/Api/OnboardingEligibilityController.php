<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Assistant\SupportContext;
use App\Http\Controllers\Controller;
use App\Models\OnboardingDismissal;
use App\Platform\Onboarding\IntegrationProgress;
use App\Platform\Onboarding\LifecycleSupport;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Enums\ProjectStatus;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only service check; this credential cannot sign in or change customer data. */
final class OnboardingEligibilityController extends Controller
{
    public function __invoke(Request $request, PlatformRoot $root, SupportContext $support, EnvironmentContext $environments): JsonResponse
    {
        $key = config('onboarding.eligibility_key');
        abort_unless(is_string($key) && strlen($key) >= 32 && hash_equals($key, $request->bearerToken() ?? ''), 401);
        $request->validate([
            'workspace_id' => ['required', 'string', 'max:100'],
            'subject_id' => ['required', 'string', 'max:100'],
            'project_id' => ['required', 'string', 'max:100'],
            'environment_id' => ['required', 'string', 'max:100'],
            'check' => ['required', 'in:cbox_id_first_sign_in_pending'],
        ]);
        $data = [];
        foreach (['workspace_id', 'subject_id', 'project_id', 'environment_id'] as $field) {
            $data[$field] = $request->string($field)->toString();
        }
        $eligible = $root->run(function () use ($data, $support, $environments): bool {
            $context = $support->context(['subject' => $data['subject_id'], 'account' => $data['workspace_id'], 'environment' => $data['environment_id']]);
            if (! $support->allowed($context, true)
                || app(Memberships::class)->activeRole($data['workspace_id'], $data['subject_id'])?->canWrite() !== true) {
                return false;
            }
            $project = Project::query()->whereKey($data['project_id'])->where('organization_id', $data['workspace_id'])->first();
            $environment = Environment::query()->whereKey($data['environment_id'])->where('project_id', $data['project_id'])->first();
            if ($project === null || $project->status !== ProjectStatus::Active || $environment === null || ! $environment->status->canServe()) {
                return false;
            }

            return $environments->runAs($environment, function () use ($data): bool {
                if (OnboardingDismissal::query()->where('subject_id', $data['subject_id'])->exists()) {
                    return false;
                }
                $goals = app(IntegrationProgress::class)->read()['goals'];

                return ($goals['create_app'] ?? false) && ! ($goals['first_sign_in'] ?? false);
            });
        });

        return response()->json(['eligible' => $eligible === true && app(LifecycleSupport::class)->canRemind($data['workspace_id']), 'observed_at' => now()->toIso8601String()])->header('Cache-Control', 'no-store');
    }
}
