<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Platform\Operators\OperatorAssurance;
use App\Platform\Operators\OperatorStanding;
use App\Platform\StepUpPlane;
use App\Platform\StepUpReason;
use Cbox\Id\Identity\Contracts\Passkeys;
use Cbox\Id\Identity\Contracts\RelyingParties;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Kernel\Crypto\Support\Base64Url;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * "CONFIRM WITH YOUR PASSKEY" — the operator plane's step-up.
 *
 * The password step-up's sibling ({@see SudoController}), with a WebAuthn assertion as
 * the proof. A password typed into the console proves nothing a script injected into the
 * console could not replay; an assertion is made by the authenticator, for this origin,
 * over a challenge issued a moment ago.
 *
 * Its endpoints sit inside the `/admin` gate (so only a signed-in operator reaches them)
 * and exempt from the proof they grant:
 *
 *  - the screen ({@see SudoController::showOperator()}), for a document navigation, and
 *    for an operator with no passkey at all: it says how to get one and how to recover;
 *  - the challenge, naming only the operator's own credentials;
 *  - the confirmation, which verifies the assertion and that the credential belongs to
 *    the person signed in, then opens the window ({@see OperatorAssurance::confirm()}).
 */
final readonly class OperatorConfirmController
{
    private const string CHALLENGE = 'operator.sudo.challenge';

    private const int CHALLENGE_TTL = 120;

    public function options(Request $request, OperatorAssurance $assurance, PlatformRoot $root, RelyingParties $relyingParties): JsonResponse
    {
        $subjectId = $assurance->subjectId();

        if ($subjectId === null) {
            return $this->signedOut();
        }

        if (! $assurance->confirmsHere()) {
            return $this->error($assurance->elsewhereMessage());
        }

        if (! $assurance->verifiesUsers()) {
            return $this->error(OperatorStanding::Unverifiable->message());
        }

        /** @var list<array{type: string, id: string}> $credentials */
        $credentials = $root->run(static fn (): array => WebAuthnCredential::query()
            ->where('user_id', $subjectId)
            ->get()
            ->map(static fn (WebAuthnCredential $credential): array => ['type' => 'public-key', 'id' => $credential->credential_id])
            ->all());

        if ($credentials === []) {
            return $this->error(OperatorStanding::NoPasskey->message());
        }

        $challenge = random_bytes(32);

        $request->session()->put(self::CHALLENGE, [
            'c' => base64_encode($challenge),
            'exp' => time() + self::CHALLENGE_TTL,
            'subject' => $subjectId,
        ]);

        return new JsonResponse([
            'challenge' => Base64Url::encode($challenge),
            'rpId' => $relyingParties->current()->id,
            // Required, and enforced by the verifier: presence alone is one factor.
            'userVerification' => 'required',
            'allowCredentials' => $credentials,
            'timeout' => 60000,
        ]);
    }

    public function confirm(Request $request, OperatorAssurance $assurance, PlatformRoot $root, Passkeys $passkeys): JsonResponse
    {
        $subjectId = $assurance->subjectId();

        if ($subjectId === null) {
            return $this->signedOut();
        }

        if (! $assurance->confirmsHere()) {
            return $this->error($assurance->elsewhereMessage());
        }

        if (! $assurance->verifiesUsers()) {
            return $this->error(OperatorStanding::Unverifiable->message());
        }

        // Throttled like a sign-in: a live session must not buy unlimited attempts.
        $key = 'operator-sudo|'.$subjectId;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return new JsonResponse(['error' => __('console.sudo_retry_seconds', ['seconds' => $seconds])], 429, ['Retry-After' => (string) $seconds]);
        }

        $challenge = $this->pullChallenge($request, $subjectId);
        $credentialId = $request->string('id')->toString();

        if ($challenge === null || $credentialId === '') {
            return $this->error('That confirmation expired. Try again.');
        }

        try {
            $verified = $root->run(fn (): string => $passkeys->authenticate($credentialId, $challenge, $request->getContent()));
        } catch (Throwable) {
            RateLimiter::hit($key, 60);

            return $this->error('That passkey could not be verified. Try again.');
        }

        // The assertion is valid for SOMEBODY. It opens this window only when that
        // somebody is the person this session belongs to.
        if (! is_string($verified) || ! hash_equals($subjectId, $verified)) {
            RateLimiter::hit($key, 60);

            return $this->error('That passkey belongs to a different account.');
        }

        RateLimiter::clear($key);
        $assurance->confirm();

        $plane = StepUpPlane::Operator;
        $intended = $request->session()->pull($plane->value.'.intended');
        StepUpReason::forget($plane->value);

        return new JsonResponse([
            'confirmed' => true,
            'plane' => $plane->label(),
            // For the full-page screen; the in-place dialog replays its own request.
            'redirect' => is_string($intended) ? $intended : route('platform.home'),
        ]);
    }

    private function pullChallenge(Request $request, string $subjectId): ?string
    {
        $stored = $request->session()->pull(self::CHALLENGE);

        if (! is_array($stored)
            || ! is_string($stored['c'] ?? null)
            || ! is_int($stored['exp'] ?? null)
            || ($stored['subject'] ?? null) !== $subjectId
            || $stored['exp'] < time()) {
            return null;
        }

        $decoded = base64_decode($stored['c'], true);

        return $decoded === false ? null : $decoded;
    }

    private function signedOut(): JsonResponse
    {
        return new JsonResponse(['error' => __('console.your_session_has_ended_sign_in_again_176c077e'), 'redirect' => route('login')], 401);
    }

    private function error(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], 422);
    }
}
