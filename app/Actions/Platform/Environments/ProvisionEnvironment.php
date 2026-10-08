<?php

declare(strict_types=1);

namespace App\Actions\Platform\Environments;

use App\Actions\Platform\AsOperator;
use App\Actions\Platform\PlatformOrganizationFields;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Identity\Contracts\PasswordPolicyGuard;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Exceptions\PolicyViolation;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\OrganizationType;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Str;

/**
 * Bootstrap an environment: its first organization and an owner administrator, so real
 * people can sign in to it. CRITICAL: it creates an account holding the keys to a tenant.
 *
 * Every question is asked INSIDE the target environment, because each has that
 * environment's answer: an email is unique per plane, and the password policy the admin's
 * password must meet is the TENANT's, not whichever plane the operator stands on. An
 * operator provisioning into a strict tenant is bound by that tenant's rules.
 *
 * The password is the operator's to choose — this is the console's own bootstrap, for an
 * environment that has nobody in it to send a link to — and it is write-only: taken,
 * checked against the policy, hashed, never returned.
 */
#[AsAction(
    name: 'platform.environments.provision',
    summary: 'Bootstrap an environment with its first organization and an owner administrator, so people can sign in to it.',
    scope: 'operator:environments:write',
    danger: Danger::Critical,
    plane: ActionPlane::Platform,
    rest: ['POST', '/environments/{environment_id}/provision'],
    status: 201,
    consoleRoutes: ['platform.environments.provision'],
    consoleGate: ConsoleGate::Operator,
    schema: 'ProvisionedEnvironment',
    tag: 'Environments',
)]
final readonly class ProvisionEnvironment implements Action
{
    public function __construct(private EnvironmentContext $context) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('environment_id')->inPath(),
            Field::string('organization_name')->required()->max(190),
            Field::string('admin_name')->required()->max(190),
            Field::string('admin_email')->required()->format('email')->max(190),
            Field::string('admin_password')->required()->max(200)->describe('Write-only. Must meet the environment\'s own password policy.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        AsOperator::id($context->principal);

        $target = AsOperator::environment($context->string('environment_id'));
        $email = trim($context->string('admin_email'));
        $password = $context->string('admin_password');

        $problem = $this->context->runAs($target, static function () use ($email, $password): ?ActionRefused {
            if (app(Subjects::class)->findByEmail($email) !== null) {
                return ActionRefused::because('email_taken', 'A user with that email already exists in this environment.', 'admin_email');
            }

            try {
                app(PasswordPolicyGuard::class)->assertAcceptableForNewSubject($password);
            } catch (PolicyViolation $violation) {
                return ActionRefused::because('weak_password', $violation->getMessage(), 'admin_password');
            }

            return null;
        });

        if ($problem instanceof ActionRefused) {
            throw $problem;
        }

        $organizationName = trim($context->string('organization_name'));
        $adminName = trim($context->string('admin_name'));

        [$organization, $subjectId] = $this->context->runAs($target, static function () use ($email, $password, $organizationName, $adminName): array {
            $subject = app(Subjects::class)->create($email, $adminName, $password);
            User::query()->where('email', $email)->update(['email_verified_at' => now()]);

            $organization = app(Organizations::class)->create(new NewOrganization(
                name: $organizationName,
                slug: Str::slug($organizationName),
                type: OrganizationType::Customer,
            ));

            app(Memberships::class)->add($organization->id, $subject->id, MembershipRole::Owner);

            return [$organization, $subject->id];
        });

        return ActionResult::item($organization, [
            'environment_id' => $target->id,
            'organization' => PlatformOrganizationFields::present($organization),
            'admin' => ['id' => $subjectId, 'email' => $email],
        ]);
    }
}
