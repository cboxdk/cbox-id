<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Actions\ActionRefused;
use App\Platform\ServiceAccess\ProductAdmission;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\OrganizationProjects;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Platform\Exceptions\EnvironmentLimitReached;
use Cbox\Id\Platform\Exceptions\OrganizationSuspended;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Compatibility provisioning for older pending customer signups and operator flows.
 * New Cbox identity signup creates only a person. The native product flow admits
 * their chosen identity team before creating a project or environment.
 *
 * Legacy pending environments are released only after email verification and a
 * current product-admission check, through TenantProvisioner::addEnvironment().
 */
final class SignupProvisioner
{
    /**
     * Both project contracts, because they answer different questions and one class
     * implements both: {@see Projects} is the WRITER (createForOrganization), and
     * {@see OrganizationProjects} is the organization-side READER (forOrganization).
     * Naming both is what makes the dependency say which capability this class uses.
     */
    public function __construct(
        private readonly Subjects $subjects,
        private readonly Memberships $memberships,
        private readonly OrganizationProjects $projects,
        private readonly Projects $projectWriter,
        private readonly Organizations $organizations,
        private readonly PlatformRoot $platformRoot,
        private readonly EnvironmentContext $context,
        private readonly TenantProvisioner $provisioner,
    ) {}

    /**
     * Everything a new customer needs EXCEPT its environment, in one transaction — so a
     * failure leaves no half-born organization, exactly as the package's own provisioner
     * guarantees.
     *
     * Refuses outright on a deployment with no platform root, matching
     * {@see TenantProvisioner::provision()}. This used to return an unhomed account
     * instead, and every caller downstream then carried a null check for a state only the
     * installer could produce; signup only ever runs on a deployment that is already
     * installed, so the honest answer to a missing root is an error rather than half a
     * customer.
     */
    public function provisionPending(TenantBlueprint $blueprint): PendingOrganization
    {
        return DB::transaction(function () use ($blueprint): PendingOrganization {
            $platformRoot = $this->platformRoot->model();

            if ($platformRoot === null) {
                throw new RuntimeException(
                    'No platform-root environment exists. Run the installer before accepting signups.',
                );
            }

            // THE CUSTOMER, THE PERSON, AND THE AUTHORITY — all inside the root, which is
            // the environment the platform's own customers live in. A tenant's end users
            // live in that tenant's own environments instead.
            [$organization, $owner, $membership] = $this->context->runAs(
                $platformRoot,
                function () use ($blueprint): array {
                    $organization = $this->organizations->create(new NewOrganization(
                        name: $blueprint->organizationName,
                        slug: $this->uniqueOrganizationSlug($blueprint->organizationName),
                    ));

                    $owner = $this->subjects->create(
                        $blueprint->ownerEmail,
                        $blueprint->ownerName,
                        $blueprint->ownerPassword,
                    );

                    $membership = $this->memberships->add($organization->id, $owner->id, MembershipRole::Owner);

                    return [$organization, $owner, $membership];
                },
            );

            $project = $this->projectWriter->createForOrganization(
                $organization->id,
                $blueprint->organizationName,
                $blueprint->environmentLimit,
            );

            return new PendingOrganization($organization, $owner, $membership, $project);
        });
    }

    /**
     * Stand up the customer's first environment — the step deferred until the owner's email
     * was verified.
     *
     * Idempotent and self-limiting: an organization that already owns an environment gets
     * nothing (so a replayed verification link, or a second address verified on the same
     * organization, can never mint a second free environment), and a suspended organization
     * or project gets nothing rather than an exception on a link click.
     */
    public function releaseEnvironment(string $organizationId, string $subjectId): ?Environment
    {
        $project = $this->platformRoot->run(
            fn (): ?Project => $this->projects->forOrganization($organizationId)->first(),
        );

        if ($project === null) {
            return null;
        }

        // Already released. Asked of the PROJECT rather than of a column on the
        // organization: `environments.account_id` was the shortcut and it is gone, and the
        // project is the thing an environment actually hangs off.
        if (Environment::query()->where('project_id', $project->id)->exists()) {
            return null;
        }

        try {
            app(ProductAdmission::class)->assertGranted($subjectId, $organizationId);

            // The organization's own status is checked INSIDE addEnvironment(), which is
            // where it belongs — an operator who suspended a signup while it was still
            // unverified must not have that decision undone by a link click.
            return $this->provisioner->addEnvironment($project, 'Production', type: EnvironmentType::Production);
        } catch (EnvironmentLimitReached|OrganizationSuspended|ActionRefused $e) {
            // A refusal on a link click is not an error page. Both of these are decisions
            // somebody made — a plan limit, or an operator's suspension — and the caller
            // renders "nothing to release" for either.
            Log::warning('deferred environment not released', [
                'organization_id' => $organizationId,
                'reason' => $e::class,
            ]);

            return null;
        }
    }

    /**
     * A slug unique across ORGANIZATIONS, resolved inside the platform root by the caller.
     *
     * The uniqueness index is `(environment_id, slug)`, so this is only correct while the
     * root is the current scope — which {@see provisionPending()} guarantees by calling it
     * from inside `runAs()`. Called from outside it, the deny-by-default tenancy scope
     * answers "no collisions" for every candidate and hands back the first one.
     */
    private function uniqueOrganizationSlug(string $name): string
    {
        $base = Str::slug($name);
        $base = $base !== '' ? $base : 'org-'.Str::lower(Str::random(6));
        $slug = $base;
        $suffix = 1;

        while (Organization::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
