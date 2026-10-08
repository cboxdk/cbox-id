<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use App\Platform\Console\LikeTerm;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;

/**
 * The organizations (the customer's own tenants) in this environment, a page at a time —
 * archived ones included, because an integration reconciling its own records needs to see
 * that one was closed. Narrow with `q` (a fragment of the name or slug) or `status`.
 */
#[AsAction(
    name: 'organizations.list',
    summary: 'List the organizations in this environment, a page at a time. Narrow by a `q` fragment of the name or slug, or by `status`.',
    scope: 'organizations:read',
    danger: Danger::Read,
    schema: 'Organization',
    tag: 'Organizations',
    rest: ['GET', '/organizations'],
)]
final class ListOrganizations implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of([
            ...self::pageFields(),
            Field::string('q')->max(190)->describe('Only organizations whose name or slug contains this.'),
            Field::string('status')->oneOf(array_map(static fn (OrganizationStatus $status): string => $status->value, OrganizationStatus::cases()))->describe('Only organizations in this state.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $query = Organization::query();

        $term = trim($context->string('q'));

        if ($term !== '') {
            $like = LikeTerm::containing($term);

            $query->where(fn (Builder $q): Builder => $q
                ->whereRaw($like->sqlFor('name'), [$like->pattern])
                ->orWhereRaw($like->sqlFor('slug'), [$like->pattern]));
        }

        $status = $context->nullableString('status');

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $this->page($query, $context, OrganizationFields::present(...));
    }
}
