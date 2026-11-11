<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\EnvironmentSudo;
use App\Platform\Operators\OperatorAssurance;
use App\Platform\StepUpPlane;
use App\Platform\StepUpPrompt;
use App\Platform\StepUpReason;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate an ENVIRONMENT control-plane action behind a fresh step-up ("sudo")
 * confirmation. The environment-plane mirror of {@see RequireSudo}, keyed off
 * {@see EnvironmentSudo}.
 *
 * The plane it guards is the wider of the two: an environment administrator acts on every
 * organization in the environment, so an action that demands a fresh password from a
 * tenant admin must demand at least as much here. It did not — the token vault was gated
 * by `sudo` on the organization plane and by nothing at all on this one, which made the
 * step-up avoidable by using the more privileged door.
 *
 * It asks the way every step-up does ({@see StepUpPrompt}) — in place for anything a
 * script made, the full page for a document navigation — but always on ITS plane: the
 * dialog it raises confirms against `/admin/sudo`, which verifies the administrator's
 * platform-root subject, and a confirmation made on the organization plane never opens it.
 */
final class RequireEnvironmentSudo
{
    public function __construct(private readonly EnvironmentSudo $sudo, private readonly OperatorAssurance $operators) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // On the platform root's console, with the operator rule on, the proof is a fresh
        // passkey: a password typed into this origin is what a hijacked session can replay.
        if ($this->operators->governs($request)) {
            return $this->operators->fresh()
                ? $next($request)
                : StepUpPrompt::demand($request, StepUpPlane::Operator, reason: StepUpReason::forRoute($request->route()?->getName()));
        }

        if ($this->sudo->confirmed()) {
            return $next($request);
        }

        return StepUpPrompt::demand(
            $request,
            StepUpPlane::Environment,
            reason: StepUpReason::forRoute($request->route()?->getName()),
        );
    }
}
