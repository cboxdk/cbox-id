<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use App\Platform\Console\LikeTerm;
use Cbox\Id\Identity\Enums\UserStatus;
use Cbox\Id\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The people in this environment, a page at a time, narrowed the ways an agent looking for
 * somebody needs: by the exact address (the one identifier a support ticket carries), by a
 * fragment of the address or name, and by status.
 *
 * The fragment search goes through {@see LikeTerm}, as the console's own search does: an
 * email address is the one column almost guaranteed to carry a literal underscore, and read
 * as a wildcard it matched people nobody was looking for. The exact match is
 * case-insensitive, because addresses are.
 */
#[AsAction(
    name: 'users.list',
    summary: 'List the users in this environment, a page at a time. Narrow by exact `email`, a `q` fragment of the address or name, or `status`.',
    scope: 'users:read',
    danger: Danger::Read,
    schema: 'User',
    tag: 'Users',
    rest: ['GET', '/users'],
)]
final class ListUsers implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of([
            ...self::pageFields(),
            Field::string('email')->max(190)->describe('Only the user with exactly this address (case-insensitive).'),
            Field::string('q')->max(190)->describe('Only users whose address or name contains this.'),
            Field::string('status')->oneOf(array_map(static fn (UserStatus $status): string => $status->value, UserStatus::cases()))->describe('Only users in this state.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $query = User::query();

        $email = $context->nullableString('email');

        if ($email !== null) {
            $query->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))]);
        }

        $term = trim($context->string('q'));

        if ($term !== '') {
            $like = LikeTerm::containing($term);

            $query->where(fn (Builder $q): Builder => $q
                ->whereRaw($like->sqlFor('email'), [$like->pattern])
                ->orWhereRaw($like->sqlFor('name'), [$like->pattern]));
        }

        $status = $context->nullableString('status');

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $this->page($query, $context, UserFields::present(...));
    }
}
