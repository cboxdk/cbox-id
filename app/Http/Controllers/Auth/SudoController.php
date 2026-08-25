<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\PageController;
use App\Http\Requests\Auth\ConfirmSudoRequest;
use App\Platform\CurrentUser;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\EnvironmentSudo;
use App\Platform\StepUpReason;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Response;

/**
 * STEP-UP RE-AUTHENTICATION — "confirm it's you", on both console planes.
 *
 * ONE CONTROLLER, TWO SESSION KEYS, and the second half is the load-bearing one. A
 * confirmation on one plane must never satisfy the other: an environment administrator
 * acts on every organization in the environment, and a tenant administrator acts on one,
 * so a step-up bought in the cheaper place would spend in the dearer.
 *
 * The two also verify against different subjects. On the organization plane it is the
 * signed-in subject. On the environment plane it is the administrator's PLATFORM-ROOT
 * subject, resolved inside `PlatformRoot::run()` — they are a subject of the root holding
 * an account membership, never a subject inside the environment they administer, and
 * `users` is environment-owned. A lookup under the ambient tenant scope would either find
 * nothing (refusing a legitimate administrator forever) or, far worse, find a same-id row
 * belonging to the tenant.
 *
 * Neither door is behind the step-up it grants.
 */
final readonly class SudoController extends PageController
{
    public function show(): Response
    {
        return $this->page('auth/sudo', "Confirm it's you", [
            /*
             * Why this screen appeared, when whatever raised it said so.
             *
             * READ, NOT SPENT. A wrong password re-renders, and the sentence explaining
             * what is waiting on the other side has to still be there on the second
             * attempt. It is forgotten in {@see self::confirm()}, with the intent it
             * belongs to.
             */
            'reason' => StepUpReason::pending('sudo'),
            'plane' => 'organization',
        ]);
    }

    public function confirm(
        ConfirmSudoRequest $request,
        Subjects $subjects,
        Sudo $sudo,
        CurrentUser $me,
    ): RedirectResponse {
        // Throttled like a sign-in. A live session must not buy unlimited password
        // guesses against the identity it belongs to.
        $key = 'sudo|'.$me->id();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $this->throttled($key);
        }

        if (! $subjects->verifyPassword($me->id(), $request->password())) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['password' => 'That password is incorrect.']);
        }

        RateLimiter::clear($key);
        $sudo->confirm();

        $intended = $request->session()->pull('sudo.intended');
        StepUpReason::forget('sudo');

        return redirect()->to(is_string($intended) ? $intended : route('settings'));
    }

    public function showEnvironment(EnvironmentAdminAuth $admin): Response
    {
        abort_if($admin->membership() === null, 403);

        return $this->page('auth/sudo', "Confirm it's you", [
            'reason' => StepUpReason::pending('environment.sudo'),
            'plane' => 'environment',
        ]);
    }

    public function confirmEnvironment(
        ConfirmSudoRequest $request,
        EnvironmentAdminAuth $admin,
        Subjects $subjects,
        PlatformRoot $root,
        EnvironmentSudo $sudo,
    ): RedirectResponse {
        abort_if($admin->membership() === null, 403);

        $subjectId = $admin->subjectId();

        // The session went away underneath the form. Sent back to the door rather than
        // confirming a step-up for nobody.
        if ($subjectId === null) {
            return to_route('admin.login');
        }

        $key = 'environment-sudo|'.$subjectId;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $this->throttled($key);
        }

        $verified = $root->run(fn (): bool => $subjects->verifyPassword($subjectId, $request->password()));

        if ($verified !== true) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['password' => 'That password is incorrect.']);
        }

        RateLimiter::clear($key);
        $sudo->confirm();

        $intended = $request->session()->pull('environment.sudo.intended');
        StepUpReason::forget('environment.sudo');

        return redirect()->to(is_string($intended) ? $intended : route('environment.home'));
    }

    private function throttled(string $key): RedirectResponse
    {
        return back()->withErrors([
            'password' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
        ]);
    }
}
