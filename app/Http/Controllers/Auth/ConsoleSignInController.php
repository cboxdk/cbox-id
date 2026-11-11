<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\PageController;
use App\Platform\ConsoleSignIn\ConsoleBackchannelLogout;
use App\Platform\ConsoleSignIn\ConsoleIdentity;
use App\Platform\ConsoleSignIn\ConsoleSession;
use App\Platform\ConsoleSignIn\ConsoleSignIn;
use App\Platform\ConsoleSignIn\ConsoleSignInFailed;
use App\Platform\ConsoleSignIn\ConsoleStepUpRequest;
use App\Platform\ConsoleSignIn\ConsoleStepUps;
use App\Platform\ConsoleSignIn\OrganizationHint;
use App\Platform\ConsoleSignIn\PendingSignIn;
use App\Platform\CurrentUser;
use App\Platform\IntendedUrl;
use App\Platform\Operators\OperatorAssurance;
use App\Platform\PlatformAuth;
use App\Platform\StepUpPlane;
use App\Platform\StepUpReason;
use App\Platform\Surfaces\ConsoleEntry;
use Cbox\Id\Identity\Contracts\MfaVerification;
use Cbox\Id\OAuthServer\Enums\AuthenticationContextClass;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * THE CONSOLE'S DOOR IN LOGIN-HOST MODE: `/auth/start` and `/auth/callback` on the app
 * host, and the back-channel logout endpoint the login host calls.
 *
 * See {@see ConsoleSignIn} for the flow, {@see ConsoleSession} for the session it starts
 * and {@see ConsoleStepUps} for the step-up that rides the same round trip. In single-host
 * mode all three answer 404: the console signs people in on its own `/login` there.
 *
 * NEVER A LOOP. Every refusal on the way back is a page with a "Try again" link, not a
 * redirect to the start: a login host that keeps answering the same thing would otherwise
 * bounce a browser between two hosts until it gave up.
 */
final readonly class ConsoleSignInController extends PageController
{
    /**
     * `GET /auth/start`: to the login host, as the console's client.
     *
     * `?step_up={plane}` asks for a re-authentication that opens that plane's window
     * (`&popup=1` when the in-place dialog opened it), `?mfa=1` for a second factor on a
     * session that has come to owe one, `?return_to=` a path on this host to land on (how
     * the login host sends somebody into the console), `?organization=` names the organization to open
     * afterwards, and `?enter={organization id}` asks to be signed in to an organization
     * that requires its own identity provider: the login host sends the person through it.
     */
    public function start(Request $request, ConsoleSignIn $flow, ConsoleStepUps $stepUps, CurrentUser $me): Response
    {
        abort_unless($flow->active($request), 404);

        $plane = $me->check() ? self::plane($request->query('step_up')) : null;
        $popup = $plane !== null && $request->boolean('popup');
        $ask = null;

        if ($plane !== null) {
            // Already open (a second tab confirmed a moment ago): nothing to ask for.
            if ($plane->confirmed()) {
                return $this->steppedUp($request, $plane, $popup, true);
            }

            $ask = $stepUps->request($request, $plane);
        } elseif ($me->check() && $request->boolean('mfa')) {
            $ask = new ConsoleStepUpRequest(acr: AuthenticationContextClass::Aal2->value);
        } elseif ($me->check() && ($enter = self::organizationToEnter($request)) !== null) {
            $ask = new ConsoleStepUpRequest(organization: $enter);
        } elseif ($me->check()) {
            return redirect()->to($this->landing($request, $me->id()));
        }

        if ($plane === null && ($to = self::returnTo($request)) !== null) {
            $request->session()->put(IntendedUrl::KEY, $to);
        } elseif ($plane === null && ($hint = OrganizationHint::from($request)) !== null) {
            $request->session()->put(IntendedUrl::KEY, route('dashboard', [OrganizationHint::PARAMETER => $hint]));
        }

        try {
            return redirect()->away($flow->begin($request, $ask, $popup));
        } catch (ConsoleSignInFailed $failed) {
            return $this->failed($request, $failed->reason);
        }
    }

    /** `GET /auth/callback`: the browser back from the login host with a code. */
    public function callback(
        Request $request,
        ConsoleSignIn $flow,
        ConsoleSession $sessions,
        ConsoleStepUps $stepUps,
        CurrentUser $me,
    ): Response {
        abort_unless($flow->active($request), 404);

        $pending = $flow->claim($request);

        if ($pending === null) {
            return $this->failed($request, 'state');
        }

        $error = $request->query('error');

        if (is_string($error) && $error !== '') {
            return $this->failed($request, 'issuer_'.$error, $pending);
        }

        $before = $me->check() ? $me->id() : null;

        try {
            $identity = $flow->redeem($request, $pending);

            if ($this->owesSecondFactor($identity)) {
                throw ConsoleSignInFailed::because('second_factor_required');
            }

            // Asked to be signed in to one organization, answered in another: stop here,
            // or the gate that asked would ask again, for ever.
            if ($pending->organization !== null && $identity->organizationId !== $pending->organization) {
                throw ConsoleSignInFailed::because('organization_not_entered');
            }

            $sessions->establish($request, $identity);

            if ($pending->organization !== null) {
                $request->session()->put(PlatformAuth::ORG_KEY, $pending->organization);
            }

            // A passkey proof given at the login host opens the operator plane from the
            // moment it was given, not from the moment this session began.
            $operators = app(OperatorAssurance::class);

            if ($identity->authTime !== null && $operators->reached($identity->amr)) {
                $operators->confirm($identity->authTime);
            }
        } catch (ConsoleSignInFailed $failed) {
            return $this->failed($request, $failed->reason, $pending);
        }

        if ($pending->plane === null) {
            return redirect()->to(IntendedUrl::pullForSubject() ?? route('dashboard'));
        }

        // A step-up is for the person who asked. Somebody else at the login host is a
        // sign-in as them, and what the first person was doing is not theirs to finish.
        if ($before !== null && $before !== $identity->subjectId) {
            $request->session()->forget($pending->plane->value.'.intended');
            StepUpReason::forget($pending->plane->value);

            return $pending->popup
                ? $this->steppedUp($request, $pending->plane, true, false)
                : redirect()->route('dashboard');
        }

        if (! $stepUps->open($request, $pending, $identity)) {
            return $pending->popup
                ? $this->steppedUp($request, $pending->plane, true, false)
                : $this->failed($request, 'step_up_not_met', $pending);
        }

        return $this->steppedUp($request, $pending->plane, $pending->popup, true);
    }

    /**
     * `POST /auth/backchannel-logout` (OIDC Back-Channel Logout 1.0 §2.5): no session, no
     * cookie, no CSRF token; the signed logout token is the whole request.
     */
    public function backchannelLogout(Request $request, ConsoleSignIn $flow, ConsoleBackchannelLogout $logout): HttpResponse
    {
        abort_unless($flow->active($request), 404);

        $token = $request->input('logout_token');
        $accepted = is_string($token) && $token !== '' && $logout->receive($token);

        return response($accepted ? '' : 'invalid_request', $accepted ? 200 : 400, ['Cache-Control' => 'no-store']);
    }

    /**
     * A step-up finished. The in-place dialog's window gets a page that tells the console
     * page behind it and closes; a full-page step-up goes back to the page that asked.
     */
    private function steppedUp(Request $request, StepUpPlane $plane, bool $popup, bool $confirmed): Response
    {
        if ($popup) {
            $this->asADoor();

            return $this->page('auth/step-up-done', __('console.confirm_it_s_you_6176eec2'), [
                'plane' => $plane->label(),
                'confirmed' => $confirmed,
                'home' => route('dashboard'),
            ])->toResponse($request);
        }

        $intended = $request->session()->pull($plane->value.'.intended');
        StepUpReason::forget($plane->value);

        return redirect()->to(is_string($intended) ? $intended : route('dashboard'));
    }

    private function failed(Request $request, string $reason, ?PendingSignIn $pending = null): Response
    {
        Log::warning('Console sign-in at the login host did not complete.', ['reason' => $reason]);

        $stepUp = $pending?->plane !== null;
        $this->asADoor();

        return $this->page('auth/console-sign-in-failed', __('auth.console_sign_in.failed_heading'), [
            'reason' => $reason,
            'stepUp' => $stepUp,
            'retryUrl' => $stepUp ? route('dashboard') : route('console.auth.start'),
        ])->toResponse($request)->setStatusCode($reason === 'client_not_provisioned' ? 503 : 400);
    }

    /**
     * These pages are doors, drawn with no console around them: the shell's shared props
     * describe a signed-in person inside an organization's URLs, which a door has none of.
     */
    private function asADoor(): void
    {
        app(CurrentUser::class)->clear();
    }

    /** Where a person who is already signed in goes: the organization named, or home. */
    private function landing(Request $request, string $subjectId): string
    {
        if (($to = self::returnTo($request)) !== null) {
            return $to;
        }

        $hint = OrganizationHint::from($request);

        return ($hint !== null ? app(OrganizationHint::class)->landing($subjectId, $hint) : null) ?? route('dashboard');
    }

    /**
     * Whether the login host answered with less than this person must sign in with. The
     * login host holds the same rule, so this is the console refusing to be the weaker door,
     * and refusing here (a page) instead of in the session gate (a redirect back to start).
     */
    private function owesSecondFactor(ConsoleIdentity $identity): bool
    {
        return app(PlatformRoot::class)->run(
            static fn (): bool => app(MfaVerification::class)->requiresVerification($identity->subjectId, $identity->amr),
        ) === true;
    }

    /**
     * `?return_to=`: the page on the app host to land on, the way every step on the login
     * host that ends in the console names it ({@see ConsoleEntry}). A path on this host and
     * nothing else: never another origin, and never the door itself.
     */
    private static function returnTo(Request $request): ?string
    {
        $path = $request->query(ConsoleEntry::RETURN);

        if (! is_string($path) || strlen($path) > 2000
            || ! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $path) === 1
            || $path === '/auth' || str_starts_with($path, '/auth/')) {
            return null;
        }

        return $path;
    }

    private static function organizationToEnter(Request $request): ?string
    {
        $id = $request->query('enter');

        return is_string($id) && $id !== '' && strlen($id) <= 64 ? $id : null;
    }

    private static function plane(mixed $label): ?StepUpPlane
    {
        foreach (StepUpPlane::cases() as $plane) {
            if ($plane->label() === $label) {
                return $plane;
            }
        }

        return null;
    }
}
