<?php

declare(strict_types=1);

use App\Platform\Billing\ProjectSubscriptions;
use App\Platform\OrganizationActivity;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Inertia\Testing\AssertableInertia;

/*
 * DELETING A PROJECT. An organization could suspend a project and never remove it, so a
 * product nobody runs any more stayed on the launchpad for good. The rule: an EMPTY project
 * goes, suspended or not; one that still holds an environment, or a plan that renews, does
 * not, and says which.
 */

beforeEach(function (): void {
    installedDeployment();
});

/** @return list<AuditEntry> */
function projectDeletions(string $organizationId): array
{
    return array_values(app(OrganizationActivity::class)->recent($organizationId)
        ->filter(static fn (AuditEntry $entry): bool => $entry->action === 'organization.project_deleted')
        ->all());
}

/** A second, empty project beside the one signup provisions. */
function emptyProject(string $organizationId, string $name = 'Cbox ISMS'): Project
{
    return app(Projects::class)->createForOrganization($organizationId, $name);
}

/** Billing answers that a plan still renews on the project, or cannot be reached at all. */
function projectPlan(bool $renews, bool $reachable = true): void
{
    app()->instance(ProjectSubscriptions::class, new class($renews, $reachable) implements ProjectSubscriptions
    {
        public function __construct(private bool $renews, private bool $reachable) {}

        public function renews(string $organizationId, string $projectId): bool
        {
            return $this->reachable ? $this->renews : throw new RuntimeException('Billing is down.');
        }
    });
}

it('deletes an empty project and records it on the organization\'s log', function (): void {
    ['organization' => $organization, 'subjectId' => $ownerId] = provisionAccount();
    $project = emptyProject($organization->id);
    signInAsMember($ownerId);

    $this->delete(route('orgs.projects.destroy', $project->id))
        ->assertRedirect(route('orgs.projects'))
        ->assertSessionHasNoErrors();

    expect(Project::query()->whereKey($project->id)->exists())->toBeFalse();

    $entries = projectDeletions($organization->id);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->actor_type)->toBe(ActorType::OrganizationMember)
        ->and($entries[0]->actor_id)->toBe($ownerId)
        ->and($entries[0]->target_id)->toBe($project->id)
        ->and($entries[0]->context)->toMatchArray(['name' => 'Cbox ISMS', 'status' => 'active']);
});

it('deletes a suspended project too', function (): void {
    ['organization' => $organization, 'subjectId' => $ownerId] = provisionAccount();
    $project = emptyProject($organization->id);
    app(Projects::class)->suspendForOrganization($organization->id, $project->id);
    signInAsMember($ownerId);

    $this->delete(route('orgs.projects.destroy', $project->id))->assertRedirect(route('orgs.projects'));

    expect(Project::query()->whereKey($project->id)->exists())->toBeFalse()
        ->and(projectDeletions($organization->id)[0]->context)->toMatchArray(['status' => 'suspended']);
});

it('refuses a project that still has environments, and keeps them', function (): void {
    ['organization' => $organization, 'subjectId' => $ownerId, 'project' => $project] = provisionAccount();
    signInAsMember($ownerId);

    $this->from(route('orgs.projects.show', $project->id))
        ->delete(route('orgs.projects.destroy', $project->id))
        ->assertRedirect(route('orgs.projects.show', $project->id))
        ->assertSessionHasErrors(['form' => __('console.project_delete_has_environments')]);

    expect(Project::query()->whereKey($project->id)->exists())->toBeTrue()
        ->and(Environment::query()->where('project_id', $project->id)->count())->toBe(1)
        ->and(projectDeletions($organization->id))->toBe([]);
});

it('refuses while a plan still renews on the project, or Billing cannot say', function (bool $reachable, string $message): void {
    ['organization' => $organization, 'subjectId' => $ownerId] = provisionAccount();
    $project = emptyProject($organization->id);
    projectPlan(renews: true, reachable: $reachable);
    signInAsMember($ownerId);

    $this->delete(route('orgs.projects.destroy', $project->id))
        ->assertSessionHasErrors(['form' => __($message)]);

    expect(Project::query()->whereKey($project->id)->exists())->toBeTrue();
})->with([
    'a renewing plan' => [true, 'console.project_delete_has_subscription'],
    'billing unreachable' => [false, 'console.project_delete_billing_unavailable'],
]);

it('refuses a member who may not manage environments', function (): void {
    ['organization' => $organization] = provisionAccount();
    $project = emptyProject($organization->id);
    [$viewer, $viewerId] = memberWithRole($organization->id, MembershipRole::Viewer, 'viewer@acme.example');
    grantNativeProductRoles($viewer, ['viewer']);
    signInAsMember($viewerId);

    $this->delete(route('orgs.projects.destroy', $project->id))->assertForbidden();

    expect(Project::query()->whereKey($project->id)->exists())->toBeTrue();
});

it('refuses a member whose access is limited to some environments', function (): void {
    ['organization' => $organization, 'project' => $default] = provisionAccount();
    $project = emptyProject($organization->id);
    $staging = app(TenantProvisioner::class)->addEnvironment($default, 'Staging');
    [$developer, $developerId] = memberWithRole($organization->id, MembershipRole::Developer, 'dev@acme.example');
    grantNativeProductRoles($developer, ['developer']);
    app(PlatformRoot::class)->run(fn () => app(Memberships::class)->setEnvironmentAccess($organization->id, $developerId, all: false, environmentIds: [$staging->id]));
    signInAsMember($developerId);

    $this->delete(route('orgs.projects.destroy', $project->id))->assertForbidden();

    expect(Project::query()->whereKey($project->id)->exists())->toBeTrue();
});

it('does not find another organization\'s project', function (): void {
    ['subjectId' => $ownerId] = provisionAccount();
    $rival = provisionAccount('owner@rival.example')['organization'];
    $theirs = emptyProject($rival->id, 'Theirs');
    signInAsMember($ownerId);

    $this->delete(route('orgs.projects.destroy', $theirs->id))->assertNotFound();

    expect(Project::query()->whereKey($theirs->id)->exists())->toBeTrue();
})->group('security');

it('tells the project page whether the project may be deleted', function (): void {
    ['organization' => $organization, 'subjectId' => $ownerId, 'project' => $default] = provisionAccount();
    $empty = emptyProject($organization->id);
    signInAsMember($ownerId);

    $this->get(route('orgs.projects.show', $default->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('project.deletable', false));

    signInAsMember($ownerId);
    $this->get(route('orgs.projects.show', $empty->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('project.deletable', true));
});

it('deletes a project through the organization API, recorded as the key', function (): void {
    $organization = provisionAccount()['organization'];
    $key = app(OrganizationApiKeys::class)->issue($organization->id, 'Ops', MembershipRole::Admin, null, ['workspace:read', 'projects:write'])->plaintext;
    $project = $this->withToken($key)->postJson('/api/v1/workspace/projects', ['name' => 'Retired'])->assertCreated()->json('data.id');

    $this->withToken($key)->deleteJson("/api/v1/workspace/projects/{$project}")->assertNoContent();

    expect(Project::query()->whereKey($project)->exists())->toBeFalse()
        ->and(projectDeletions($organization->id)[0]->actor_type)->toBe(ActorType::Service);

    // Gone is gone: a second call finds nothing rather than deleting something else.
    $this->withToken($key)->deleteJson("/api/v1/workspace/projects/{$project}")->assertNotFound();
});

it('refuses the API a project with environments, with a code a caller can branch on', function (): void {
    ['organization' => $organization, 'project' => $project] = provisionAccount();
    $key = app(OrganizationApiKeys::class)->issue($organization->id, 'Ops', MembershipRole::Admin, null, ['workspace:read', 'projects:write'])->plaintext;

    $this->withToken($key)->deleteJson("/api/v1/workspace/projects/{$project->id}")
        ->assertStatus(422)
        ->assertJsonPath('error', 'project_has_environments');

    expect(Project::query()->whereKey($project->id)->exists())->toBeTrue();
});
