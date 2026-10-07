<?php

declare(strict_types=1);

namespace App\Actions\Platform\Organizations;

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
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\OrganizationType;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Str;

/**
 * Create an organization inside one environment — a tenant of somebody else's product,
 * from the operator's side of the glass.
 *
 * THE ENVIRONMENT IS NAMED, not ambient. The console creates into whichever environment
 * the operator has pointed it at, and passes that one; over the API the caller says which.
 * Everything then runs AS that environment, so the organization, its slug's uniqueness and
 * its parent are all that plane's — a parent from another environment is not found rather
 * than spliced in.
 */
#[AsAction(
    name: 'platform.organizations.create',
    summary: 'Create an organization inside an environment, optionally under a parent organization of the same environment.',
    scope: 'operator:organizations:write',
    danger: Danger::Critical,
    plane: ActionPlane::Platform,
    rest: ['POST', '/environments/{environment_id}/organizations'],
    status: 201,
    consoleRoutes: ['platform.organizations.store'],
    consoleGate: ConsoleGate::Operator,
    schema: 'PlatformOrganization',
    tag: 'Organizations',
)]
final readonly class CreateTenantOrganization implements Action
{
    public function __construct(private EnvironmentContext $context) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('environment_id')->inPath(),
            Field::string('name')->required()->max(190),
            Field::string('type')->required()->oneOf(array_map(static fn (OrganizationType $type): string => $type->value, OrganizationType::cases())),
            Field::string('parent_id')->nullable()->describe('An organization of the same environment to create it under.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        AsOperator::id($context->principal);

        $environment = AsOperator::environment($context->string('environment_id'));
        $name = trim($context->string('name'));
        $type = OrganizationType::from($context->string('type'));
        $parentId = $context->nullableString('parent_id');

        $organization = $this->context->runAs($environment, function () use ($name, $type, $parentId): Organization {
            $organizations = app(Organizations::class);

            // Through the SCOPED reader: a parent from another plane is not found.
            if ($parentId !== null && $organizations->find($parentId) === null) {
                throw ActionRefused::notFound('parent organization');
            }

            return $organizations->create(new NewOrganization(
                name: $name,
                slug: $this->uniqueSlug($name),
                type: $type,
                parentId: $parentId,
            ));
        });

        return ActionResult::item($organization, PlatformOrganizationFields::present($organization));
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';
        $slug = $base;
        $n = 2;

        while (Organization::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
