<?php

declare(strict_types=1);

namespace App\Actions\SupportSessions;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\SupportAccess\Contracts\SupportAccess;

/**
 * End a support session now: its unused codes die and every token it minted is revoked.
 * A session from another environment is not found; ending one already ended changes
 * nothing.
 */
#[AsAction(
    name: 'support_sessions.end',
    summary: 'End a support session now: every token it issued is revoked.',
    scope: 'support:write',
    danger: Danger::Destructive,
    tag: 'Support sessions',
    rest: ['DELETE', '/support-sessions/{id}'],
    status: 204,
    consoleRoutes: ['environment.support-sessions.end'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class EndSupportSession implements Action
{
    public function __construct(private SupportAccess $support) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The support session id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $id = $context->string('id');

        if (! $this->support->end($id, (string) $context->actor()->id)) {
            throw ActionRefused::notFound('support session');
        }

        return ActionResult::none($id);
    }
}
