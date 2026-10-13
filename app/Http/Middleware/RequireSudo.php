<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\StepUpPlane;
use App\Platform\StepUpPrompt;
use App\Platform\StepUpReason;
use App\Platform\Sudo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate an action behind a fresh step-up ("sudo") confirmation. Adding a
 * credential — a passkey or a linked social provider — establishes a new,
 * persistent way in, so it is exactly as sensitive as removing one: a hijacked
 * but stale session must not be able to plant persistence.
 *
 * HOW IT ASKS is {@see StepUpPrompt}'s, shared with the environment plane's gate and the
 * action-level one: a request a script made (every Inertia visit, the passkey ceremony's
 * JSON calls) is refused with a 403 the page turns into an in-place "Confirm it's you"
 * dialog that replays the request once confirmed; a document navigation is sent to the
 * full-page screen and brought back to the page it asked for.
 *
 * The in-place refusal is THROWN, never returned — see {@see StepUpPrompt} for why a
 * returned non-redirect is a gate that can be swallowed.
 */
final class RequireSudo
{
    public function __construct(private readonly Sudo $sudo) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->sudo->confirmed()) {
            return $next($request);
        }

        return StepUpPrompt::demand(
            $request,
            StepUpPlane::Organization,
            reason: StepUpReason::forRoute($request->route()?->getName()),
        );
    }
}
