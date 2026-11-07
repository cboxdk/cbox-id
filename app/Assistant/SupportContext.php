<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Platform\Actions\ActionRefused;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\NativeProduct\NativeEnvironmentAccess;
use App\Platform\NativeProduct\NativeProductAccess;
use App\Platform\Onboarding\IntegrationProgress;
use App\Platform\OrganizationAccess;
use App\Platform\PlatformAuth;
use App\Platform\ServiceAccess\ProductAdmission;
use Cbox\AiAssistant\Connector\Support\ToolContext;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Contracts\OrganizationProjects;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/** Cbox support is for the product's workspace members, not their customers' sign-in sessions. */
final readonly class SupportContext
{
    public function __construct(private PlatformRoot $root, private Memberships $memberships, private Subjects $subjects) {}

    /** @return array{subject: string, account: string, environment: string}|null */
    public function sessionIdentity(Request $request): ?array
    {
        $environment = app(EnvironmentContext::class)->current()?->environmentKey();
        $root = $this->root->environment()?->environmentKey();
        if ($environment === null || $root === null) {
            return null;
        }
        $admin = app(EnvironmentAdminAuth::class);
        if ($environment === $root) {
            $current = app(CurrentUser::class);
            $subject = $current->check() ? $current->id() : $admin->signedInRootSubject();
            $account = $current->organizationId() ?? $request->session()->get(PlatformAuth::ORG_KEY);
        } else {
            $subject = $admin->subjectId();
            $account = $admin->workspaceId();
        }
        if (! is_string($subject) || $subject === '' || ! is_string($account) || $account === '') {
            return null;
        }
        $identity = ['subject' => $subject, 'account' => $account, 'environment' => $environment];

        return $this->allowed($this->context($identity)) ? $identity : null;
    }

    public function sessionUrl(Request $request): ?string
    {
        if (config('ai-assistant-connector.support.enabled') !== true || ($identity = $this->sessionIdentity($request)) === null) {
            return null;
        }
        $environment = app(ConsoleScope::class)->plane() === ConsolePlane::Environment;

        return ($environment ? '/admin/support/session' : '/support/session').'?scope='.hash('sha256', implode(':', $identity));
    }

    public function allowed(ToolContext $context, bool $withEnvironments = false): bool
    {
        return $this->root->run(function () use ($context, $withEnvironments): bool {
            $scope = $context->principal['context'] ?? null;
            $subject = $context->userId();
            $account = $context->accountId();
            if (! $context->verified || $subject === null || $account === null || ! is_array($scope)
                || ($scope['service'] ?? null) !== 'cbox-id' || ($scope['tenant'] ?? null) !== $account
                || ! is_string($scope['environment'] ?? null) || ! $this->subjects->isActive($subject)) {
                return false;
            }
            $organization = Organization::query()->find($account);
            if (! $organization instanceof Organization || OrganizationAccess::isRevoked($organization->status)
                || $this->memberships->activeRole($account, $subject) === null) {
                return false;
            }
            try {
                $access = app(NativeProductAccess::class)->forSubject($subject, $account);
                if (! $access->any() || ($withEnvironments && ! $access->canReadEnvironments())) {
                    return false;
                }
                if ($withEnvironments) {
                    app(ProductAdmission::class)->assertGranted($subject, $account);
                }
            } catch (ActionRefused) {
                return false;
            }
            if ($scope['environment'] === $this->root->environment()?->environmentKey()) {
                return true;
            }
            $environment = Environment::query()->find($scope['environment']);

            return $access->canReadEnvironments() && $environment instanceof Environment && $environment->status->canServe()
                && in_array($environment->id, app(NativeEnvironmentAccess::class)->ids($account, $subject), true);
        }) === true;
    }

    /** @return array<string, mixed> */
    public function read(ToolContext $context, bool $withEnvironments): array
    {
        abort_unless($this->allowed($context, $withEnvironments), 403);
        $result = $this->root->run(function () use ($context, $withEnvironments): array {
            $account = $context->accountId();
            $subject = $context->userId();
            abort_unless($account !== null && $subject !== null, 403);
            $organization = Organization::query()->findOrFail($account);
            $base = ['organization' => $organization->name,
                'role' => $this->memberships->activeRole($account, $subject)?->value,
                'observed_at' => now()->toIso8601String(), 'url' => rtrim(config()->string('app.url'), '/').'/projects'];
            if (! $withEnvironments) {
                return $base;
            }
            $projects = app(OrganizationProjects::class)->forOrganization($account);
            $allowed = app(NativeEnvironmentAccess::class)->ids($account, $subject);
            $scope = (array) ($context->principal['context'] ?? []);
            $active = $scope['environment'] ?? null;
            $environments = Environment::query()->whereIn('id', $allowed)
                ->when($active !== $this->root->environment()?->environmentKey(), fn ($query) => $query->whereKey($active))
                ->whereIn('project_id', $projects->pluck('id')->all())->orderBy('name')->get()->groupBy('project_id');

            if (! app(NativeProductAccess::class)->forSubject($subject, $account)->isOwner()) {
                $projects = $projects->filter(fn (Project $project): bool => $environments->has($project->id));
            }

            return [...$base, 'projects' => $projects->map(fn (Project $project): array => [
                'name' => $project->name, 'status' => $project->status->value,
                'environments' => $environments->get($project->id, new Collection)->map(fn (Environment $environment): array => [
                    'name' => $environment->name, 'slug' => $environment->slug,
                    'type' => $environment->type->value, 'status' => $environment->status->value,
                ])->values()->all(),
            ])->values()->all()];
        });
        abort_unless(is_array($result), 403);

        return $result;
    }

    /** @return array<string, mixed> */
    public function onboarding(ToolContext $context): array
    {
        abort_unless($this->allowed($context, true), 403);

        $result = $this->root->run(function () use ($context): array {
            $account = $context->accountId();
            $subject = $context->userId();
            abort_unless($account !== null && $subject !== null, 403);
            $allowed = app(NativeEnvironmentAccess::class)->ids($account, $subject);
            $projects = app(OrganizationProjects::class)->forOrganization($account)->pluck('id')->all();
            $scope = $context->principal['context'] ?? [];
            $active = is_array($scope) ? ($scope['environment'] ?? null) : null;
            $environments = Environment::query()->whereIn('id', $allowed)->whereIn('project_id', $projects)
                ->when($active !== $this->root->environment()?->environmentKey(), fn ($query) => $query->whereKey($active))
                ->get()->filter(fn (Environment $environment): bool => $environment->status->canServe());

            return ['environments' => $environments->map(fn (Environment $environment): array => app(EnvironmentContext::class)->runAs($environment, fn (): array => app(IntegrationProgress::class)->read())
            )->values()->all()];
        });
        abort_unless(is_array($result), 403);

        return $result;
    }

    /** @param array{subject: string, account: string, environment: string} $identity */
    public function context(array $identity): ToolContext
    {
        return new ToolContext(['sub' => $identity['subject'], 'account_id' => $identity['account'],
            'context' => ['service' => 'cbox-id', 'tenant' => $identity['account'], 'environment' => $identity['environment']]], verified: true);
    }
}
