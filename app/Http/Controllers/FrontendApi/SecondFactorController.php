<?php

declare(strict_types=1);

namespace App\Http\Controllers\FrontendApi;

use App\Platform\FrontendApi\LoginTickets;
use App\Platform\PlatformAuth;
use Cbox\Id\FrontendApi\Models\PublishableKey;
use Cbox\Id\OAuthServer\Enums\AuthMethod;
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

        // The key is part of the claim rather than a check after it: a ticket minted by
        // one customer's page must not be completable from another's — both hold valid keys
        // against this environment — and checking afterwards meant the wrong page could
        // spend the real person's five attempts before being refused.
        $ticket = $this->tickets->claimAttempt($token, $stage, $key);

        if ($ticket === null) {
            // Unknown token, wrong key, wrong stage, expired, or out of attempts — one
            // answer, for the same reason a wrong password and an unknown address are one.
            return $this->refuse();
        }

        $satisfied = $stage === 'pending_otp'
            ? $this->completeOtp($request, $ticket->subject_id, $code)
            : $this->completeMfa($request, $ticket->subject_id, $code);

        if (! $satisfied) {
            return $this->refuse();
        }

        // Promoted rather than re-minted: a pending ticket that survived its own promotion
        // would be a second chance at a factor already used. The `amr` now names both
        // factors, and it names them the way every other door does — see {@see AuthMethod}.
        return new JsonResponse([
            'status' => 'ok',
            'login_ticket' => $this->tickets->promote($ticket, AuthMethod::forSecondFactorCode()),
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
        //
        // ONE INPUT COSTS ONE FAILURE. Both methods record a failure of their own, so
        // running them in sequence over the same string charged the account lockout twice
        // for one mistyped code — halving the real threshold for a person and doubling an
        // attacker's rate of locking somebody out. The hosted form has two fields and
        // charges once; here the shape of the code decides which method is even asked.
        return $this->looksLikeTotp($code)
            ? $this->auth->completeMfa($request, $code)
            : $this->auth->completeMfaWithRecoveryCode($request, $code);
    }

    /**
     * Whether this input can only be a TOTP code.
     *
     * A TOTP code is six digits; a recovery code is not. Deciding on the shape rather than
     * trying both is what keeps one wrong code costing one attempt — see the caller.
     */
    private function looksLikeTotp(string $code): bool
    {
        return preg_match('/^\d{6}$/', $code) === 1;
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
