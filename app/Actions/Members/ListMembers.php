<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\Actions\Organizations\OrganizationFields;
use App\Http\Resources\Environment\MemberResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\Kernel\Tenancy\GenericTenant;
use Cbox\Id\Organization\Models\Membership;
use Illuminate\Database\Eloquent\Collection;

/**
 * An organization's roster, a page at a time: each member's tier and status, with the
 * person's name and address.
 *
 * Memberships are TENANT-owned: with no tenant in context the scope answers nothing, so the
 * read runs as this organization — and binds it in the WHERE clause as well, so the roster
 * stays this organization's wherever tenant scoping is suspended. The people on the page are
 * looked up in one query, not one per row.
 */
#[AsAction(
    name: 'members.list',
    summary: 'List an organization\'s members, a page at a time, with each one\'s tier, status, name and email.',
    scope: 'members:read',
    danger: Danger::Read,
    schema: 'Member',
    tag: 'Members',
    rest: ['GET', '/organizations/{organization_id}/members'],
)]
final readonly class ListMembers implements Action
{
    use Paginates;

    public function __construct(
        private TenantContext $tenants,
        private Subjects $subjects,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization id.'),
            ...self::pageFields(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organization = OrganizationFields::find($context, $context->string('organization_id'));

        $result = $this->tenants->runAs(GenericTenant::of($organization->id), fn (): ActionResult => $this->page(
            Membership::query()->where('organization_id', $organization->id),
            $context,
            static fn (Membership $membership): array => ['membership' => $membership],
        ));

        /** @var Collection<int, Membership> $rows */
        $rows = $result->value;

        $people = $this->subjects->findMany(array_values(array_map(
            static fn (Membership $membership): string => $membership->user_id,
            $rows->all(),
        )));

        $payload = array_values(array_map(
            static fn (Membership $membership): array => MemberResource::from($membership, $people[$membership->user_id] ?? null),
            $rows->all(),
        ));

        return ActionResult::page($rows, $payload, $result->meta ?? []);
    }
}
