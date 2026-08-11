<?php

declare(strict_types=1);

namespace App\Http\Controllers\FrontendApi;

use App\Platform\FrontendApi\LoginTickets;
use App\Platform\PlatformAuth;
use Cbox\Id\FrontendApi\Models\PublishableKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The second half of an embedded sign-in: the factor the password did not satisfy.
 *
 * THE PENDING STATE TRAVELS AS A TOKEN because it has to. The hosted form keeps it in the
 * session between the two requests; a page on somebody else's origin carries no session
 * cookie, so there is nothing there to keep. The token IS that state, and it is a ticket
 * row in the `pending_mfa` stage — the same record that becomes the sign-in, so the
 * subject and the environment cannot drift between the two halves.
 *
 * IT PUTS THE SUBJECT BACK INTO THE SESSION AND CALLS THE APP'S OWN CODE. Verifying the
 * TOTP here directly would be a second implementation of the challenge, and it would be
 * the one missing whatever `completeMfa()` grows next — the replay guard, the audit entry,
 * the `amr` it establishes. So the pending subject is written where that method looks for
 * it, and the method runs exactly as it does for somebody on the hosted page.
 *
 * A WRONG CODE COSTS AN ATTEMPT, NOT THE SIGN-IN. Five are allowed, counted before the
 * code is checked so a crash or a walked-away caller still spends one. Running out and
 * typing the wrong digits answer identically: saying "you have run out" confirms the
 * ticket was real.
 */
class SecondFactorController
{
    public function __construct(
        private readonly PlatformAuth $auth,
        private readonly LoginTickets $tickets,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $key = $request->attributes->get('cbox_publishable_key');
        $token = $request->input('mfa_token');
        $code = $request->input('code');

        if (! $key instanceof PublishableKey || ! is_string($token) || ! is_string($code) || $token === '' || $code === '') {
            return $this->refuse();
        }

        $stage = $request->input('method') === 'otp' ? 'pending_otp' : 'pending_mfa';

        $ticket = $this->tickets->claimAttempt($token, $stage);

        if ($ticket === null) {
            // Unknown token, wrong stage, expired, or out of attempts — one answer, for
            // the same reason a wrong password and an unknown address are one answer.
            return $this->refuse();
        }

        // A ticket minted by one customer's page must not be completable from another's,
        // even though both hold valid keys against this environment.
        if ($ticket->publishable_key_id !== $key->id) {
            return $this->refuse();
        }

        $satisfied = $stage === 'pending_otp'
            ? $this->completeOtp($request, $ticket->subject_id, $code)
            : $this->completeMfa($request, $ticket->subject_id, $code);

        if (! $satisfied) {
            return $this->refuse();
        }

        // Promoted rather than re-minted: a pending ticket that survived its own promotion
        // would be a second chance at a factor already used. The `amr` now names both.
        return new JsonResponse([
            'status' => 'ok',
            'login_ticket' => $this->tickets->promote($ticket, [...$ticket->amr, $stage === 'pending_otp' ? 'otp' : 'mfa']),
            'expires_in' => 60,
        ]);
    }

    /**
     * Run the app's own TOTP challenge, with the pending subject put where it looks.
     */
    private function completeMfa(Request $request, string $subjectId, string $code): bool
    {
        $this->auth->holdForMfa($request, $subjectId);

        // The recovery-code path is the escape hatch when the authenticator is gone, and it
        // is the same escape hatch here — an embedded form that could not accept one would
        // strand exactly the people the hatch exists for.
        return $this->auth->completeMfa($request, $code)
            || $this->auth->completeMfaWithRecoveryCode($request, $code);
    }

    private function completeOtp(Request $request, string $subjectId, string $code): bool
    {
        $this->auth->holdForOtpStepUp($request, $subjectId);

        return $this->auth->completeOtpStepUp($request, $code);
    }

    private function refuse(): JsonResponse
    {
        return new JsonResponse(['status' => 'invalid'], 401);
    }
}
