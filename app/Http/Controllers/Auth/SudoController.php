<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\PageController;
use App\Http\Requests\Auth\ConfirmSudoRequest;
use App\Platform\CurrentUser;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\EnvironmentSudo;
use App\Platform\StepUpPlane;
use App\Platform\StepUpPrompt;
use App\Platform\StepUpReason;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
 *
 * TWO WAYS TO ASK, ONE ANSWER. The in-place "Confirm it's you" dialog posts here as JSON
 * (`Accept: application/json`) and is answered in JSON — 200 when the window is open, 422
 * with the field error, 429 when throttled — because the page that opened it is holding the
 * action and replays it itself. The full-page screen posts as a form and is redirected to
 * the page it came from. The throttle, the subject verified and the session key written
 * are the same for both: the dialog is a second face on this endpoint, not a second door.
 */
final readonly class SudoController extends PageController
{
    public function show(Request $request): Response
    {
        $this->rememberWhereFrom($request, StepUpPlane::Organization);

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
    ): RedirectResponse|JsonResponse {
        // Throttled like a sign-in. A live session must not buy unlimited password
        // guesses against the identity it belongs to.
        $key = 'sudo|'.$me->id();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $this->throttled($request, $key);
        }

        if (! $subjects->verifyPassword($me->id(), $request->password())) {
            RateLimiter::hit($key, 60);

            return $this->incorrect($request);
        }

        RateLimiter::clear($key);
        $sudo->confirm();

        return $this->confirmed($request, StepUpPlane::Organization, route('account'));
    }

    public function showEnvironment(Request $request, EnvironmentAdminAuth $admin): Response
    {
        abort_unless($admin->check(), 403);

        $this->rememberWhereFrom($request, StepUpPlane::Environment);

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
    ): RedirectResponse|JsonResponse {
        abort_unless($admin->check(), 403);

        $subjectId = $admin->subjectId();

        // The session went away underneath the form. Sent back to the door rather than
        // confirming a step-up for nobody.
        if ($subjectId === null) {
            return self::wantsJson($request)
                ? new JsonResponse(['error' => 'Your session has ended. Sign in again.', 'redirect' => route('admin.login')], 401)
                : to_route('admin.login');
        }

        $key = 'environment-sudo|'.$subjectId;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $this->throttled($request, $key);
        }

        $verified = $root->run(fn (): bool => $subjects->verifyPassword($subjectId, $request->password()));

        if ($verified !== true) {
            RateLimiter::hit($key, 60);

            return $this->incorrect($request);
        }

        RateLimiter::clear($key);
        $sudo->confirm();

        return $this->confirmed($request, StepUpPlane::Environment, route('environment.home'));
    }

    /**
     * The window is open: tell the dialog so, or send the full-page screen back.
     *
     * The intent and its sentence are spent here either way — the dialog's page replays the
     * action it was holding, and a stale intent left behind would send the NEXT full-page
     * step-up somewhere this one meant to go.
     *
     * WHERE THE FULL PAGE GOES BACK TO: the page the person was on. The intent is that page
     * (or the gated page they asked for), recorded when the step-up was raised or, failing
     * that, when this screen was opened. `$lastResort` — the plane's home — is only for a
     * screen reached with no page behind it at all, a typed `/sudo`.
     */
    private function confirmed(Request $request, StepUpPlane $plane, string $lastResort): RedirectResponse|JsonResponse
    {
        $intended = $request->session()->pull($plane->value.'.intended');
        StepUpReason::forget($plane->value);

        if (self::wantsJson($request)) {
            return new JsonResponse(['confirmed' => true, 'plane' => $plane->label()]);
        }

        return redirect()->to(is_string($intended) ? $intended : $lastResort);
    }

    private function incorrect(Request $request): RedirectResponse|JsonResponse
    {
        return $this->refuse($request, 'That password is incorrect.', 422);
    }

    private function throttled(Request $request, string $key): RedirectResponse|JsonResponse
    {
        $seconds = RateLimiter::availableIn($key);

        return $this->refuse($request, 'Too many attempts. Try again in '.$seconds.' seconds.', 429, ['Retry-After' => (string) $seconds]);
    }

    /**
     * A refusal on the password field — in the validation shape Laravel answers JSON with,
     * so the dialog reads it exactly as it reads a missing password.
     *
     * @param  array<string, string>  $headers
     */
    private function refuse(Request $request, string $message, int $status, array $headers = []): RedirectResponse|JsonResponse
    {
        if (self::wantsJson($request)) {
            return new JsonResponse(['message' => $message, 'errors' => ['password' => [$message]]], $status, $headers);
        }

        return back()->withErrors(['password' => $message]);
    }

    /**
     * The in-place dialog, which asks for JSON by name. An Inertia visit (the full-page
     * form) never does: it is answered with a redirect it follows, as it always was.
     */
    private static function wantsJson(Request $request): bool
    {
        return $request->header('X-Inertia') === null && $request->wantsJson();
    }

    /**
     * Opened with nothing waiting — a link to `/sudo`, a step-up that recorded no page —
     * remember the page the person came from, so confirming returns them THERE rather than
     * to a generic overview they never asked for. Only a same-origin path, and never this
     * screen itself.
     */
    private function rememberWhereFrom(Request $request, StepUpPlane $plane): void
    {
        if ($request->session()->has($plane->value.'.intended')) {
            return;
        }

        $from = StepUpPrompt::sameOriginPath($request, $request->headers->get('referer'));

        if ($from !== null && parse_url($from, PHP_URL_PATH) !== parse_url($plane->pageUrl(), PHP_URL_PATH)) {
            $request->session()->put($plane->value.'.intended', $from);
        }
    }
}
