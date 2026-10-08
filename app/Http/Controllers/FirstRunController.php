<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\PointAtFirstRun;
use App\Http\Requests\ClaimDeploymentRequest;
use App\Platform\Enums\AttemptOutcome;
use App\Platform\Install\Contracts\PlatformInstaller;
use App\Platform\Install\Contracts\SetupTokens;
use App\Platform\Install\Enums\DeploymentShape;
use App\Platform\Install\InstalledPlatform;
use App\Platform\Install\InstallPlan;
use App\Platform\Install\OperatorIdentity;
use App\Platform\PlaneResolver;
use App\Platform\PlatformAuth;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Response;

/**
 * FIRST RUN — the only door into an unclaimed deployment.
 *
 * This is the safe version of "lazy-create the first tenant on the first request". Plain
 * lazy-create hands a public identity provider to whoever reaches it first, and on the open
 * internet that is a scanner rather than the operator. So the screen exists ONLY while the
 * platform is empty ({@see PointAtFirstRun} 404s it the moment it is
 * not), and it demands the setup token, which is handed out where only console access can
 * read it — never rendered here, never in the URL — and kept, hashed, in the database every
 * replica shares, so the look and the submit may land on different instances.
 *
 * IT DOES NOT CHOOSE THE DEPLOYMENT SHAPE. A web request cannot durably write `.env` — the
 * file may be read-only, the config may be cached, and a horizontally-scaled deployment
 * would only have written it on one node — so a screen that offered multi-tenancy would
 * leave the platform provisioned one way and configured another. It installs the shape this
 * deployment is already configured for, and refuses outright when that configuration is
 * incoherent: `php artisan cbox-id:install` is where the shape is decided, because it is
 * the caller that can write the answer down.
 */
final readonly class FirstRunController extends PageController
{
    /**
     * How loudly somebody may guess at the token.
     *
     * NOT a brute-force bound — the token is 32 random bytes — but a bound on turning an
     * unclaimed deployment into a free CPU-burning oracle.
     */
    private const ATTEMPTS = 5;

    public function show(PlatformInstaller $installer, SetupTokens $tokens, PlaneResolver $planes): Response
    {
        abort_unless($installer->isEmpty(), 404);

        // Arming happens on the first LOOK, not on the first submission: the operator needs
        // the token before they can fill anything in. A scanner triggers the same mint and
        // learns nothing — only a hash is stored, and the value goes to the log only where
        // the deployment opted in. Not on an un-migrated database: the token lives in a
        // table, and the page below tells the operator to migrate first.
        if ($installer->ready()) {
            $tokens->arm();
        }

        return $this->page('auth/first-run', __('auth.first_run.title'), [
            // The configured shape, shown so the operator sees what they are about to create.
            'multiTenant' => $planes->isMultiTenant(),
            // Multi-tenant with nowhere for the console to live — nothing safe to install.
            'misconfigured' => $planes->misconfigured(),
            /*
             * The database is reachable but has no schema. An un-migrated deployment is
             * empty, so it lands here — and it is the most likely way to arrive, because a
             * container that has never run its migrations is exactly the box nobody has
             * installed yet. Offering the form would mean a submission that dies on a
             * missing table; saying so costs one question.
             */
            'unmigrated' => ! $installer->ready(),
            'claimHref' => route('first-run.claim'),
        ]);
    }

    public function claim(
        ClaimDeploymentRequest $request,
        PlatformInstaller $installer,
        SetupTokens $tokens,
        PlaneResolver $planes,
        PlatformAuth $auth,
        PlatformRoot $platformRoot,
    ): RedirectResponse {
        // Re-asked on the write, not inherited from the render: everything this endpoint is
        // allowed to do rests on these two, and the render happened on another request.
        abort_unless($installer->isEmpty(), 404);
        abort_if($planes->misconfigured() || ! $installer->ready(), 409);

        $key = 'first-run|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS)) {
            return back()->withInput()->withErrors([
                'token' => trans_choice('auth.common.too_many_attempts', RateLimiter::availableIn($key)),
            ]);
        }

        if (! $tokens->matches($request->token())) {
            RateLimiter::hit($key, 60);

            return back()->withInput()->withErrors([
                'token' => __('auth.first_run.token_mismatch'),
            ]);
        }

        RateLimiter::clear($key);

        /*
         * Serialised, so two concurrent submissions cannot both pass the emptiness check
         * and each provision a platform root. The installer re-checks inside; the window
         * between check and write is exactly what this closes.
         */
        $lock = Cache::lock('cbox:first-run', 10);

        abort_unless($lock->get() === true, 429);

        try {
            $installed = $installer->install(new InstallPlan(
                shape: $planes->isMultiTenant() ? DeploymentShape::MultiTenant : DeploymentShape::SingleTenant,
                operator: new OperatorIdentity($request->email(), $request->name(), $request->password()),
                environmentName: $request->environmentName(),
                organizationName: $request->organizationName(),
            ));
        } finally {
            $lock->release();
        }

        // SPENT, not merely superseded. The route dies with the emptiness check above, but a
        // token left behind is a live secret for a door that no longer exists.
        $tokens->forget();

        /*
         * Signed in through the ORDINARY path, with the password they just chose — the
         * setup token is authority to install, never a credential, so it does not become a
         * session. If what the installer created cannot authenticate, the operator finds out
         * here rather than at their next sign-in.
         *
         * That path is the subject one: an operator is a subject, and the second credential
         * store they used to authenticate against is gone. Run in the platform root's scope,
         * because that is where the installer just wrote them and where their session has to
         * live — subjects and sessions are environment-owned, and the ambient environment on
         * this request is still the pre-install bootstrap default.
         */
        $signedIn = $platformRoot->run(
            fn (): bool => $auth->attemptPassword($request, $request->email(), $request->password()) === AttemptOutcome::Ok,
        ) === true;

        // Anything short of a full session — a second factor, a policy hold, or a deployment
        // whose operators are not subjects yet — goes to the sign-in door rather than to a
        // console that would bounce them back to it anyway.
        return redirect()->to($signedIn
            ? route('platform.environments')
            : $this->signInUrl($installed, $planes));
    }

    /**
     * Where the person who just installed this deployment signs in.
     *
     * In the SaaS shape the door lives on the account host and nowhere else — the bulkheads
     * see to that — so a relative redirect would land on whichever host the install happened
     * to be performed against and 404 there.
     */
    private function signInUrl(InstalledPlatform $installed, PlaneResolver $planes): string
    {
        $host = $planes->consoleHost();

        if (! $installed->shape->isMultiTenant() || $host === null || $host === request()->getHost()) {
            return route('login');
        }

        return request()->getScheme().'://'.$host.route('login', absolute: false);
    }
}
