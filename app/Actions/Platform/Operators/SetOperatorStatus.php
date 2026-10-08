<?php

declare(strict_types=1);

namespace App\Actions\Platform\Operators;

use App\Actions\Platform\AsOperator;
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
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Exceptions\CannotSuspendLastOperator;
use Cbox\Id\Platform\Models\PlatformOperator;

/**
 * Suspend a platform operator, or bring one back. The state is named rather than flipped,
 * so a retry cannot undo itself.
 *
 * BOTH REFUSALS ARE SPELT OUT, because both are recoverable states somebody has to
 * understand: suspending YOURSELF would lock you out of the console you are standing in
 * (and out of the token you are calling with), and suspending the LAST active operator
 * would lock everyone out of it permanently — the roster refuses that on its own terms.
 * The change is recorded by the roster itself, as the acting operator.
 */
#[AsAction(
    name: 'platform.operators.set_status',
    summary: 'Suspend a platform operator, or reactivate one. Never yourself, and never the last active operator.',
    scope: 'operator:operators:write',
    danger: Danger::Critical,
    plane: ActionPlane::Platform,
    rest: ['PUT', '/operators/{operator_id}/status'],
    consoleRoutes: ['platform.operators.toggle'],
    consoleGate: ConsoleGate::Operator,
    schema: 'PlatformOperator',
    tag: 'Operators',
)]
final readonly class SetOperatorStatus implements Action
{
    public function __construct(private PlatformOperators $operators) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('operator_id')->inPath(),
            Field::string('status')->required()->oneOf(['active', 'suspended']),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $actorId = AsOperator::id($context->principal);
        $operator = PlatformOperator::query()->find($context->string('operator_id'))
            ?? throw ActionRefused::notFound('operator');

        if ($context->string('status') === 'active') {
            if (! $operator->isActive()) {
                $this->operators->reactivate($operator->id, $actorId);
            }
        } elseif ($operator->isActive()) {
            // Checked BEFORE the roster is asked, so it cannot be reached at all.
            if ($operator->id === $actorId) {
                throw ActionRefused::because('self_suspension', 'You cannot suspend the operator you are currently signed in as.', 'operator');
            }

            try {
                $this->operators->suspend($operator->id, $actorId);
            } catch (CannotSuspendLastOperator) {
                throw ActionRefused::because('last_operator', 'You cannot suspend the last active operator — the console would lock everyone out.', 'operator');
            }
        }

        $fresh = $operator->fresh() ?? $operator;

        return ActionResult::item($fresh, OperatorFields::present($fresh));
    }
}
