<?php

declare(strict_types=1);

namespace App\Actions\Pipes;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use Cbox\Id\Pipes\Models\Pipe;

/**
 * The third-party providers people in this environment can connect their accounts to:
 * which OAuth app each uses, the scopes it asks for, and which apps may lease the tokens.
 * Never a client secret.
 */
#[AsAction(
    name: 'pipes.list',
    summary: 'List the pipes — the third-party providers people can connect their accounts to — with their scopes and the apps granted their tokens. Never a client secret.',
    scope: 'pipes:read',
    danger: Danger::Read,
    schema: 'Pipe',
    tag: 'Pipes',
    rest: ['GET', '/pipes'],
)]
final class ListPipes implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of(self::pageFields());
    }

    public function handle(ActionContext $context): ActionResult
    {
        return $this->page(Pipe::query(), $context, static fn (Pipe $pipe): array => PipeFields::present($pipe));
    }
}
