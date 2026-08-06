<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\OperatorEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Point the operator console's READS at the plane the operator selected.
 *
 * This used to live in {@see SetEnvironment}, which runs in the `web` group — ahead of
 * every authentication middleware. That was fine while an operator was a separate
 * credential store: `platform_operators` is not environment-owned, so the pinned plane
 * could be ambient for the whole request without touching how the operator themselves
 * was resolved.
 *
 * It stopped being fine the moment the operator became a subject. `auth_sessions` IS
 * environment-owned and hard-scoped (deny-by-default), and an operator's subject lives
 * in the PLATFORM ROOT. So pinning a tenant plane before {@see Authenticate} runs makes
 * the operator's own session invisible to the query that resolves it: the console would
 * sign them out on the very next request after they targeted a customer's plane — and
 * `EnvironmentScope` fails closed, so it would look like an expired session rather than
 * like a bug.
 *
 * Hence the split. The HOST decides the environment identity is resolved in (SetEnvironment,
 * `web` group); the operator's selection decides the environment the console READS, and
 * is applied here — inside the operator group, after {@see AuthenticateOperator} has
 * established that this person runs the deployment.
 *
 * That ordering is also what makes the pin safe to honour without re-asking who the
 * viewer is. A stale selection left in the session of someone who is no longer an
 * operator can never be applied, because they never reach this middleware: the platform
 * pages 404 for them. Read in the `web` group the same stale value would have re-aimed
 * an ordinary end user's requests at a plane of their choosing.
 */
final class TargetEnvironment
{
    public function __construct(
        private readonly EnvironmentContext $context,
        private readonly OperatorEnvironment $target,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $this->target->slug();

        // A selection naming an environment that has since been deleted is DROPPED
        // rather than left to fail closed on every read: the console would render every
        // list empty with nothing to say why, and the operator has no way to clear it.
        if ($slug !== null) {
            $environment = Environment::query()->where('slug', $slug)->first();

            if ($environment === null) {
                $this->target->release();
            } else {
                $this->context->set($environment);
            }
        }

        return $next($request);
    }
}
