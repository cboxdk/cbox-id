<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\OrganizationType;
use Cbox\Id\Organization\Exceptions\SlugAlreadyTaken;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Provision an organization — one of the customer's own tenants — and, when named, make an
 * existing user its OWNER, in the one transaction the action runs in: an app never sees a
 * team that exists with nobody in charge of it.
 *
 * THE SLUG. A slug that is sent is the caller's, and a taken one is refused
 * (`slug_taken`) — which is what makes a create retry-safe without an idempotency key.
 * One that is not sent is the name walked to the first free one (`acme`, `acme-2`).
 */
#[AsAction(
    name: 'organizations.create',
    summary: 'Create an organization in this environment, optionally with an existing user as its owner, a parent and metadata.',
    scope: 'organizations:write',
    danger: Danger::Write,
    schema: 'Organization',
    tag: 'Organizations',
    rest: ['POST', '/organizations'],
    status: 201,
    consoleRoutes: ['environment.organizations.store'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class CreateOrganization implements Action
{
    public function __construct(
        private Organizations $organizations,
        private Memberships $memberships,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('name')->required()->max(190),
            Field::string('slug')->nullable()->max(190)->describe('Letters, digits, dashes and underscores. Send your own to make a retry fail with `slug_taken` instead of creating a second one.'),
            Field::string('type')->oneOf(array_map(static fn (OrganizationType $type): string => $type->value, OrganizationType::cases()))->describe('Default `customer`.'),
            Field::string('parent_id')->nullable()->max(64)->describe('An organization of this environment to nest it under.'),
            Field::string('owner_user_id')->nullable()->max(64)->describe('An existing user of this environment, who becomes its owner.'),
            Field::object('metadata', [])->nullable()->describe('Free-form text values the integrator keeps on the organization.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $name = trim($context->string('name'));
        $slug = $context->nullableString('slug');

        if ($slug !== null && preg_match('/^[\pL\pM\pN_-]+$/u', $slug) !== 1) {
            throw ValidationException::withMessages(['slug' => 'The slug may only contain letters, numbers, dashes and underscores.']);
        }

        if ($slug !== null && $this->organizations->bySlug($slug) !== null) {
            throw $this->slugTaken();
        }

        $parentId = $context->nullableString('parent_id');

        if ($parentId !== null && Organization::query()->whereKey($parentId)->doesntExist()) {
            throw ActionRefused::because('parent_not_found', 'No organization with that parent_id exists in this environment.', 'parent_id');
        }

        $ownerId = $context->nullableString('owner_user_id');

        // The environment-scoped user store: an id from another environment is no user here.
        if ($ownerId !== null && User::query()->whereKey($ownerId)->doesntExist()) {
            throw ActionRefused::because('user_not_found', 'No user with that owner_user_id exists in this environment.', 'owner_user_id');
        }

        $metadata = OrganizationFields::metadata($context->array('metadata'));

        try {
            $organization = $this->organizations->create(new NewOrganization(
                name: $name,
                slug: $slug ?? $this->freeSlug($name),
                type: OrganizationType::tryFrom($context->string('type')) ?? OrganizationType::Customer,
                parentId: $parentId,
                settings: $metadata === [] ? [] : ['metadata' => $metadata],
            ));
        } catch (SlugAlreadyTaken) {
            // A concurrent create took it between the check above and the insert.
            throw $this->slugTaken();
        }

        // `add()` is the one door that may seat an Owner: on a brand-new organization there
        // is nobody to transfer it from.
        if ($ownerId !== null) {
            $this->memberships->add($organization->id, $ownerId, MembershipRole::Owner);
        }

        return ActionResult::item($organization, OrganizationFields::present($organization));
    }

    /** The name as a slug, walked to the first one nobody holds. */
    private function freeSlug(string $name): string
    {
        $base = Str::slug($name);
        $base = $base !== '' ? $base : 'org';
        $slug = $base;

        for ($n = 2; $this->organizations->bySlug($slug) !== null; $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    private function slugTaken(): ActionRefused
    {
        return ActionRefused::because('slug_taken', 'That slug is already in use in this environment.', 'slug');
    }
}
