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
use App\Rules\NotBreached;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Illuminate\Support\Facades\Validator;

/**
 * Add a platform operator — somebody who will hold authority over the whole deployment.
 * The most critical write there is: nothing else here can make a second person able to
 * do everything this one can.
 *
 * The password is the console's own rule for an operator's: at least twelve characters,
 * and not one already published in a breach. It is write-only — the roster stores the
 * operator's ordinary subject and its hash, and nothing returns it.
 */
#[AsAction(
    name: 'platform.operators.create',
    summary: 'Add a platform operator: a person with authority over the whole deployment.',
    scope: 'operator:operators:write',
    danger: Danger::Critical,
    plane: ActionPlane::Platform,
    rest: ['POST', '/operators'],
    status: 201,
    consoleRoutes: ['platform.operators.store'],
    consoleGate: ConsoleGate::Operator,
    schema: 'PlatformOperator',
    tag: 'Operators',
)]
final readonly class CreateOperator implements Action
{
    public function __construct(private PlatformOperators $operators) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('name')->required()->max(190),
            Field::string('email')->required()->format('email')->max(190),
            Field::string('password')->required()->min(12)->max(200)->describe('Write-only. At least 12 characters, and not one known from a breach.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        AsOperator::id($context->principal);

        $email = trim($context->string('email'));
        $password = $context->string('password');

        $breached = Validator::make(['password' => $password], ['password' => [new NotBreached]]);

        if ($breached->fails()) {
            throw ActionRefused::because('breached_password', (string) $breached->errors()->first('password'), 'password');
        }

        if ($this->operators->findByEmail($email) !== null) {
            throw ActionRefused::because('operator_exists', 'An operator with that email already exists.', 'email');
        }

        $operator = $this->operators->create($email, $password, trim($context->string('name')));

        return ActionResult::item($operator, OperatorFields::present($operator));
    }
}
