<?php

declare(strict_types=1);

namespace App\Actions\Pipes;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use Cbox\Id\Pipes\Models\PipeConnection;

/**
 * Who has connected an account through a pipe, and whether each still works. Never a
 * token.
 */
#[AsAction(
    name: 'pipes.connections.list',
    summary: 'List the accounts people connected through a pipe: who, which account, granted scopes, status (active / needs_reauth) and when the token expires. Never a token.',
    scope: 'pipes:read',
    danger: Danger::Read,
    schema: 'PipeConnection',
    tag: 'Pipes',
    rest: ['GET', '/pipes/{id}/connections'],
)]
final class ListPipeConnections implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of([
            PipeFields::id(),
            Field::string('user_id')->max(128)->describe('Only this person\'s connection.'),
            ...self::pageFields(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $pipe = PipeFields::pipe($context);
        $userId = $context->nullableString('user_id');

        return $this->page(
            PipeConnection::query()->where('pipe_id', $pipe->id)->when($userId !== null, fn ($query) => $query->where('user_id', $userId)),
            $context,
            static fn (PipeConnection $connection): array => PipeFields::presentConnection($connection),
        );
    }
}
