<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Platform\Actions\Approvals\ActionApprovalGate;
use App\Platform\Actions\Idempotency\IdempotencyGuard;
use App\Platform\Actions\Principal\AnnotatesTrail;
use App\Platform\Actions\Principal\Principal;
use App\Platform\OAuth\Exceptions\StepUpAuthenticationRequired;
use App\Platform\OAuth\ManagementStepUp;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The one way an action runs, whichever door asked.
 *
 *  1. the principal may run this action at all ({@see Principal::authorize()});
 *  1b. a person's token has signed in recently and strongly enough for a Critical action,
 *      when the deployment asks that ({@see ManagementStepUp}) — before anything is held
 *      for an approval the token could not then use;
 *  2. the input is valid against the action's own schema — the same rules for a form, a
 *     JSON body and a tool call, so a refusal is the same `validation_failed` everywhere;
 *  3. a write with an `Idempotency-Key` from a machine principal runs at most once;
 *  3a. an action that can refuse from its input alone says so now ({@see Preflight}) —
 *      an unsafe URL, a foreign id, a missing owner — so nobody approves a request that
 *      could never run;
 *  3b. an action the principal's step-up policy names waits for a person's approval
 *      ({@see ActionApprovalGate}), and runs once that approval is spent;
 *  4. the action runs inside a transaction, so a refusal half-way leaves neither a change
 *     nor an audit line claiming one;
 *  5. every audit entry it causes names the door it came through (`via`) and, when it
 *     waited for one, the approval it spent — said once here, through {@see ActionTrail},
 *     rather than by each action and each framework service it calls — and whatever the
 *     principal itself adds ({@see AnnotatesTrail}).
 *
 * Doors translate the outcome: an {@see ActionResult}, an {@see ActionRefused}, an
 * {@see AuthorizationException}, a {@see StepUpAuthenticationRequired} or a
 * {@see ValidationException}. Nothing here knows about
 * HTTP, redirects or MCP.
 */
final readonly class ActionRunner
{
    public function __construct(
        private ActionRegistry $registry,
        private IdempotencyGuard $idempotency,
        private ActionApprovalGate $approvals,
        private ManagementStepUp $stepUp,
        private Container $container,
    ) {}

    /**
     * @param  class-string<Action>|ActionDefinition  $action
     * @param  array<string, mixed>  $input
     *
     * @throws ActionRefused
     * @throws AuthorizationException
     * @throws StepUpAuthenticationRequired
     * @throws ValidationException
     */
    public function run(string|ActionDefinition $action, Principal $principal, array $input, ?string $idempotencyKey = null, ?string $approvalId = null, ?ActionVia $via = null): ActionResult
    {
        $definition = $action instanceof ActionDefinition ? $action : $this->registry->forClass($action);
        $via ??= ActionVia::inferredFrom($principal);

        $principal->authorize($definition);
        $this->stepUp->enforce($principal, $definition);

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($input, $definition->input()->rules())->validate();

        // Asked of the container per run, never held: the trail is SCOPED, and a runner held
        // by a controller the router keeps would push onto a trail the audit log no longer
        // reads once a queued job has reset the scoped instances.
        $trail = $this->container->make(ActionTrail::class);

        $execute = fn (): ActionResult => $trail->within($via, function () use ($definition, $principal, $validated, $approvalId, $via, $trail): ActionResult {
            /** @var Action $handler */
            $handler = $this->container->make($definition->class);
            $context = new ActionContext($principal, $validated, $via);

            // Before the approval gate, so a refusal the input already decides is answered
            // instead of held for a person who would approve a request that cannot run.
            // Inside the idempotent section, like the gate: a replay of a request that ran
            // answers what it answered, whatever a fresh check would say now.
            if ($handler instanceof Preflight) {
                $handler->preflight($context);
            }

            // Inside the idempotent section: a retry of a request that already ran replays its
            // answer without asking the person again, and a held request stores nothing.
            $spent = $this->approvals->enforce($principal, $definition, $validated, $approvalId);

            if ($spent !== null) {
                $trail->approved($spent, $principal->approverSubjectId());
            }

            if ($principal instanceof AnnotatesTrail) {
                $trail->annotate($principal->trailContext());
            }

            return $definition->danger->writes()
                ? DB::transaction(static fn (): ActionResult => $handler->handle($context))
                : $handler->handle($context);
        });

        if ($idempotencyKey !== null && $idempotencyKey !== '' && $definition->danger->writes() && $principal->supportsIdempotency()) {
            return $this->idempotency->once($principal, $idempotencyKey, $definition, $validated, $execute);
        }

        return $execute();
    }
}
