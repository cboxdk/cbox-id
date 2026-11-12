<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Platform\Console\LikeTerm;
use App\Platform\Enterprise\EnterpriseAudit;
use App\Platform\Environments\TenantBaseDomain;
use App\Platform\NativeProduct\NativeProductAccess;
use App\Platform\Organizations\OrganizationLinks;
use Cbox\AiAssistant\Connector\Profile\ResourceItem;
use Cbox\AiAssistant\Connector\Profile\ResourceStatus;
use Cbox\AiAssistant\Connector\Profile\Signals;
use Cbox\AiAssistant\Connector\Support\ToolContext;
use Cbox\Id\Federation\Enums\ConnectionStatus;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Enums\MfaRequirement;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Enums\ProjectStatus;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The part of the customer Cbox ID knows, for Cbox Assistant's customer profile: the
 * workspace's projects and environments as pickable resources, and account signals.
 *
 * Authorization is {@see SupportContext}'s, the same check the signed tools use. By default
 * a principal sees exactly what they see in the console; a verified support elevation
 * widens it to the whole SAME workspace. Queries are bounded: the SDK serves 50 items, so
 * 51 are read.
 */
final readonly class CustomerProfile
{
    public const string PROJECTS = 'id_projects';

    public const string ENVIRONMENTS = 'id_environments';

    /** One more than the SDK serves, so it can say the list was cut. */
    private const int LIMIT = 51;

    private const int RECENT_DAYS = 7;

    /** Audit actions that read as a change a customer or support agent cares about. */
    private const array CHANGES = [
        'organization.project_created' => 'Project created',
        'organization.project_renamed' => 'Project renamed',
        'organization.project_suspended' => 'Project suspended',
        'organization.project_reactivated' => 'Project reactivated',
        'organization.environment_created' => 'Environment created',
        'organization.custom_domain_verified' => 'Custom domain verified',
        'organization.custom_domain_removed' => 'Custom domain removed',
        'organization.member_added' => 'Member added as',
        'organization.member_removed' => 'Member removed',
        'organization.member_role_changed' => 'Member role changed to',
        'organization.ownership_transferred' => 'Ownership transferred',
        'organization.renamed' => 'Organization renamed',
        'organization.settings_updated' => 'Organization settings changed',
        'connection.activated' => 'SSO connection activated',
        EnterpriseAudit::SSO_CONNECTION_CREATED => 'SSO connection created',
        EnterpriseAudit::SSO_CONNECTION_UPDATED => 'SSO connection changed',
        EnterpriseAudit::SSO_CONNECTION_DISABLED => 'SSO connection disabled',
        EnterpriseAudit::SSO_CONNECTION_DELETED => 'SSO connection deleted',
        EnterpriseAudit::SSO_REQUIRED => 'SSO requirement changed',
    ];

    public function __construct(private SupportContext $support, private PlatformRoot $root) {}

    /** The resource guard: the signed tools' check, needing environment access unless elevated. */
    public function allowed(ToolContext $context): bool
    {
        return $this->support->allowed($context, $context->supportAccess() === null);
    }

    /**
     * @param  array<string, string>  $filters
     * @return list<ResourceItem>
     */
    public function projects(ToolContext $context, array $filters): array
    {
        return array_values($this->root->run(function () use ($context, $filters): array {
            $scope = $this->support->scope($context, $context->supportAccess() !== null);
            $counts = $scope->environments()->toBase()->selectRaw('project_id, count(*) as c')
                ->groupBy('project_id')->pluck('c', 'project_id');
            $term = mb_strtolower(trim($filters['q'] ?? ''));

            return $scope->projects
                ->filter(fn (Project $project): bool => $term === '' || str_contains(mb_strtolower($project->name.' '.$project->slug), $term))
                ->sortBy(fn (Project $project): string => mb_strtolower($project->name))
                ->take(self::LIMIT)
                ->map(fn (Project $project): ResourceItem => new ResourceItem(
                    id: $project->id,
                    label: $project->name,
                    status: $project->status === ProjectStatus::Suspended ? ResourceStatus::Warning : ResourceStatus::Ok,
                    statusLabel: $project->status === ProjectStatus::Suspended ? 'Suspended: no new environments' : null,
                    url: $this->projectUrl($project->organization_id, $project->id),
                    attributes: ['slug' => $project->slug],
                    metrics: ['environments' => $this->count($counts, $project->id)],
                    updatedAt: $this->stamp($project, 'updated_at'),
                ))->values()->all();
        }) ?? []);
    }

    /**
     * @param  array<string, string>  $filters
     * @return list<ResourceItem>
     */
    public function environments(ToolContext $context, array $filters): array
    {
        return array_values($this->root->run(function () use ($context, $filters): array {
            $term = trim($filters['q'] ?? '');
            $environments = $this->support->scope($context, $context->supportAccess() !== null)->environments()
                ->when($term !== '', function (Builder $query) use ($term): void {
                    $like = LikeTerm::containing($term);
                    $query->where(fn (Builder $inner) => $inner->whereRaw($like->sqlFor('name'), [$like->pattern])
                        ->orWhereRaw($like->sqlFor('slug'), [$like->pattern])
                        ->orWhereRaw($like->sqlFor('domain'), [$like->pattern]));
                })
                ->orderBy('name')->limit(self::LIMIT)->get();
            $ids = $environments->pluck('id')->all();
            [$users, $apps] = app(EnvironmentContext::class)->withoutScope(fn (): array => [
                User::query()->selectRaw('environment_id, count(*) as c')->whereIn('environment_id', $ids)->groupBy('environment_id')->pluck('c', 'environment_id'),
                Client::query()->selectRaw('environment_id, count(*) as c')->whereIn('environment_id', $ids)->groupBy('environment_id')->pluck('c', 'environment_id'),
            ]);
            $base = $this->baseDomain();
            $owners = Project::query()->whereIn('id', $environments->pluck('project_id')->filter()->unique()->all())->pluck('organization_id', 'id');

            return $environments->map(fn (Environment $environment): ResourceItem => new ResourceItem(
                id: $environment->id,
                label: $environment->name,
                status: $environment->status === EnvironmentStatus::Suspended ? ResourceStatus::Inactive : ResourceStatus::Ok,
                statusLabel: $environment->status === EnvironmentStatus::Suspended ? 'Suspended: sign-in is refused' : null,
                url: $environment->project_id === null ? null : $this->projectUrl(is_string($owners[$environment->project_id] ?? null) ? $owners[$environment->project_id] : null, $environment->project_id),
                parent: $environment->project_id === null ? null : self::PROJECTS.':'.$environment->project_id,
                attributes: [
                    'type' => $environment->type->value,
                    'host' => $environment->domain ?? $environment->slug.'.'.$base,
                    'custom_domain' => $environment->domain !== null,
                    'custom_domain_verified' => $environment->domain !== null && $environment->domain_verified_at !== null,
                ],
                metrics: ['users' => $this->count($users, $environment->id), 'applications' => $this->count($apps, $environment->id)],
                updatedAt: $this->stamp($environment, 'updated_at'),
            ))->values()->all();
        }) ?? []);
    }

    /**
     * Account signals. Signals have no guard of their own: a principal the signed tools would
     * refuse gets nothing. Members see their own role and what they can reach; seats need the
     * member-roster permission, and security posture and recent changes are an administrator's
     * view (the console's audit log) — or a verified support elevation's.
     */
    public function signals(ToolContext $context): Signals
    {
        if (! $this->support->allowed($context, false)) {
            return Signals::make();
        }
        $elevated = $context->supportAccess() !== null;
        $seesEnvironments = $elevated || $this->support->allowed($context, true);

        return $this->root->run(function () use ($context, $elevated, $seesEnvironments): Signals {
            $account = (string) $context->accountId();
            $subject = (string) $context->userId();
            $organization = Organization::query()->find($account);
            $role = app(Memberships::class)->activeRole($account, $subject);
            if (! $organization instanceof Organization || $role === null) {
                return Signals::make();
            }
            $signals = Signals::make()->fact('member_role', $role->value);
            $since = $this->stamp($organization, 'created_at');
            if ($since !== null) {
                $signals->customerSince($since);
            }
            if ($seesEnvironments) {
                $signals->fact('environments', $this->support->scope($context, $elevated)->environments()->count());
            }
            if ($elevated || app(NativeProductAccess::class)->forSubject($subject, $account)->canReadMembers()) {
                $signals->seats(app(Memberships::class)->countForOrganization($account));
            }
            if (! $elevated && ! $role->canManageOrganization()) {
                return $signals;
            }
            $signals->fact('sso_enabled', Connection::query()->where('organization_id', $account)->where('status', ConnectionStatus::Active)->exists())
                ->fact('two_factor_enforced', app(AuthPolicies::class)->resolve($account)->mfa === MfaRequirement::Required);
            AuditEntry::query()->where('scope', $account)->whereIn('action', array_keys(self::CHANGES))
                ->where('recorded_at', '>=', now()->subDays(self::RECENT_DAYS))
                ->orderByDesc('sequence')->limit(10)->get()
                ->each(function (AuditEntry $entry) use ($signals): void {
                    if ($entry->recorded_at !== null) {
                        $signals->change($this->describe($entry), $entry->recorded_at, $this->url('/audit?entry='.$entry->id));
                    }
                });

            return $signals;
        }) ?? Signals::make();
    }

    /** @param  Collection<array-key, mixed>  $counts  a grouped `count(*)` keyed by id */
    private function count(Collection $counts, string $id): int
    {
        $count = $counts->get($id, 0);

        return is_numeric($count) ? (int) $count : 0;
    }

    private function stamp(Model $model, string $column): ?DateTimeInterface
    {
        $value = $model->getAttribute($column);

        return $value instanceof DateTimeInterface ? $value : null;
    }

    private function describe(AuditEntry $entry): string
    {
        $what = self::CHANGES[$entry->action] ?? $entry->action;
        $detail = $entry->context['name'] ?? $entry->context['domain'] ?? $entry->context['role'] ?? null;

        if (! is_string($detail) || $detail === '') {
            return str_ends_with($what, ' as') || str_ends_with($what, ' to') ? substr($what, 0, -3) : $what;
        }

        return str_ends_with($what, ' as') || str_ends_with($what, ' to') ? $what.' '.$detail : $what.': '.$detail;
    }

    /** An HTTPS console link; the SDK drops any other scheme, so none is invented here. */
    /**
     * A project's page in the console of the organization that owns it, at that
     * organization's address ({@see OrganizationLinks::addressOf()}): never the viewer's
     * last-used organization, which is not necessarily the project's.
     */
    private function projectUrl(?string $organizationId, string $projectId): ?string
    {
        $slug = $organizationId === null ? null : app(OrganizationLinks::class)->addressOf($organizationId)['slug'] ?? null;

        return $slug === null ? null : $this->url('/orgs/'.$slug.'/projects/'.$projectId);
    }

    private function url(string $path): ?string
    {
        $base = rtrim(config()->string('app.url'), '/');

        return str_starts_with($base, 'https://') ? $base.$path : null;
    }

    private function baseDomain(): string
    {
        return TenantBaseDomain::canonical() ?? (string) parse_url(config()->string('app.url'), PHP_URL_HOST);
    }
}
