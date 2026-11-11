<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Console\EnvironmentConsolePath;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\NativeProduct\NativeEnvironmentAccess;
use App\Platform\Operators\OperatorGate;
use App\Platform\PlaneResolver;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * THE ONE DOOR INTO AN ENVIRONMENT CONSOLE on the app host:
 * `/orgs/{org}/projects/{project}/environments/{environment}/…`, and `/admin/environment/…`
 * for the platform root's own environment.
 *
 * The console used to live on each environment's own host, where the host said which
 * environment this was and a session of its own (redeemed from a signed handoff) said who
 * administered it. On the app host one session reaches every environment its person may
 * administer, so the boundary moves into the path, and this middleware is all of it:
 *
 * 1. Only on the app host of a multi-tenant deployment. A tenant's host never serves it.
 * 2. The path names one environment, and only when its organization, project and
 *    environment belong together ({@see EnvironmentConsolePath::resolve()}). Anything
 *    else is a 404, and so is an environment that is suspended.
 * 3. The environment becomes the request's {@see EnvironmentContext} — BEFORE route model
 *    binding runs, which is why this class sits ahead of `SubstituteBindings` in the
 *    middleware priority (bootstrap/app.php). A record id from another environment in the
 *    rest of the path then finds nothing.
 * 4. The person is authorized for THIS environment on every request, never from a cached
 *    allow: an active membership of the owning organization, the native product's read
 *    role, and {@see NativeEnvironmentAccess} listing this
 *    environment ({@see EnvironmentAdminAuth::membership()}); or, on the root's console, an
 *    active platform operator. Anybody else signed in gets a 404, the answer an
 *    environment they cannot see should give. Signed out, they are sent to sign in.
 *
 * Whether they may CHANGE anything is `env.admin`'s question, asked right after this.
 */
final class ResolveEnvironmentFromPath
{
    /** The environment id this request's console was opened for. */
    public const ATTRIBUTE = 'cbox.console_environment';

    public function __construct(
        private readonly PlaneResolver $planes,
        private readonly EnvironmentConsolePath $paths,
        private readonly EnvironmentContext $environments,
        private readonly EnvironmentAdminAuth $auth,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $path = $route instanceof Route ? $route->parameter(EnvironmentConsolePath::PARAMETER) : null;

        abort_unless(
            $route instanceof Route
            && is_string($path)
            && $this->planes->isMultiTenant()
            && $this->planes->isPlatformRootHost($request->getHost()),
            404,
        );

        $environment = $this->paths->resolve($path);

        // A suspended environment has no console, as it had none on its own host, where the
        // resolver refuses whatever cannot serve: an operator's suspension stops its
        // administrators changing its keys, vault and sign-in as well as its traffic.
        abort_if($environment === null || ! $environment->status->canServe(), 404);

        $this->environments->set($environment);
        $request->attributes->set(self::ATTRIBUTE, $environment->environmentKey());

        if ($path === EnvironmentConsolePath::ROOT) {
            $this->auth->enterRootConsole();
        } else {
            $this->auth->enterEnvironmentConsole();
        }

        // Every `route('environment.…')` this request builds stays in this environment.
        URL::defaults(['environmentPath' => $path]);

        // The controllers never see it: their parameters are the record ids after it.
        $route->forgetParameter(EnvironmentConsolePath::PARAMETER);

        if (! $this->auth->canRead()) {
            return $this->refuse($request);
        }

        // The platform root's own console is the operator plane too: its administrators are
        // the operators, and what it configures is how everybody signs in.
        if ($path === EnvironmentConsolePath::ROOT && $this->auth->rootOperator() !== null) {
            $asked = app(OperatorGate::class)->admit($request);

            if ($asked !== null) {
                return $asked;
            }
        }

        return $next($request);
    }

    private function refuse(Request $request): Response
    {
        // Signed in as somebody who cannot see this environment: it does not exist for them.
        abort_if($this->auth->signedInRootSubject() !== null, 404);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Your session has expired.', 'redirect' => route('login')], 401);
        }

        return redirect()->guest(route('login'));
    }
}
