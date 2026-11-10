<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\EnvironmentAdminAuth;
use App\Platform\SignedInPage;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\MfaMandate;
use Cbox\Id\Identity\Contracts\PasswordExpiry;
use Cbox\Id\Platform\PlatformRoot;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `env.admin`: what an environment console's administrator may do, once
 * {@see ResolveEnvironmentFromPath} has said which environment this is and that they may
 * see it.
 *
 * A read is open to every administrator the path middleware let in, a Reader included. A
 * write needs the native product's manage role ({@see EnvironmentAdminAuth::check()}) and
 * is a 403 without it — re-asked here on every request, so a role taken away while a tab
 * sat open takes effect on the next click.
 *
 * The holds the app host's own sign-in gate applies (a password to change, a second factor
 * to enrol) apply here too, so the environment console is not a way round them: the person
 * is sent to the console that holds them.
 */
final class AuthenticateEnvironmentAdmin
{
    public function __construct(
        private readonly EnvironmentAdminAuth $auth,
        private readonly MfaMandate $mfaMandate,
        private readonly AdminPasswords $adminPasswords,
        private readonly PasswordExpiry $passwordExpiry,
        private readonly PlatformRoot $root,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $subjectId = $this->auth->subjectId();

        // Never reached without one: the path middleware refuses first. Said here as well,
        // because this is the alias a route names when it means "an administrator only".
        abort_if($subjectId === null, 404);

        if ($this->held($subjectId)) {
            return redirect()->route('dashboard');
        }

        abort_unless($request->isMethodSafe() || $this->auth->check(), 403);

        return SignedInPage::serve($request, $next);
    }

    /**
     * Whether the console's sign-in gate would hold this person on its own pages first.
     *
     * Asked in the PLATFORM ROOT, where the person is a subject: by now the request's
     * environment is the one being administered, whose rows say nothing about them.
     */
    private function held(string $subjectId): bool
    {
        return $this->root->run(fn (): bool => $this->adminPasswords->requiresChange($subjectId)
            || $this->passwordExpiry->hasExpired($subjectId)
            || $this->mfaMandate->requiresEnrolment($subjectId)) === true;
    }
}
