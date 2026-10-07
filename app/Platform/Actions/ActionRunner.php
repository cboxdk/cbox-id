<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Platform\Actions\Approvals\ActionApprovalGate;
use App\Platform\Actions\Idempotency\IdempotencyGuard;
use App\Platform\Actions\Principal\Principal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The one way an action runs, whichever door asked.
 *
 *  1. the principal may run this action at all ({@see Principal::authorize()});
 *  2. the input is valid against the action's own schema — the same rules for a form, a
 *     JSON body and a tool call, so a refusal is the same `validation_failed` everywhere;
 *  3. a write with an `Idempotency-Key` from a machine principal runs at most once;
 *  3b. an action the principal's step-up policy names waits for a person's approval
 *      ({@see ActionApprovalGate}), and runs once that approval is spent;
 *  4. the action runs inside a transaction, so a refusal half-way leaves neither a change
 *     nor an audit line claiming one.
 *
 * Doors translate the outcome: an {@see ActionResult}, an {@see ActionRefused}, an
 * {@see AuthorizationException} or a {@see ValidationException}. Nothing here knows about
 * HTTP, redirects or MCP.
 */
final readonly class ActionRunner
{
    public function __construct(
        private ActionRegistry $registry,
        private IdempotencyGuard $idempotency,
        private ActionApprovalGate $approvals,
        private Container $container,
    ) {}

    /**
     * @param  class-string<Action>|ActionDefinition  $action
     * @param  array<string, mixed>  $input
     *
     * @throws ActionRefused
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function run(string|ActionDefinition $action, Principal $principal, array $input, ?string $idempotencyKey = null, ?string $approvalId = null): ActionResult
    {
        $definition = $action instanceof ActionDefinition ? $action : $this->registry->forClass($action);

        $principal->authorize($definition);

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($input, $definition->input()->rules())->validate();

        $execute = function () use ($definition, $principal, $validated, $approvalId): ActionResult {
            // Inside the idempotent section: a retry of a request that already ran replays its
            // answer without asking the person again, and a held request stores nothing.
            $this->approvals->enforce($principal, $definition, $validated, $approvalId);

            /** @var Action $handler */
            $handler = $this->container->make($definition->class);
            $context = new ActionContext($principal, $validated);

            return $definition->danger->writes()
                ? DB::transaction(static fn (): ActionResult => $handler->handle($context))
                : $handler->handle($context);
        };

        if ($idempotencyKey !== null && $idempotencyKey !== '' && $definition->danger->writes() && $principal->supportsIdempotency()) {
            return $this->idempotency->once($principal, $idempotencyKey, $definition, $validated, $execute);
        }

        return $execute();
    }
}
