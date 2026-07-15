<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use App\Platform\RiskGuard;
use Cbox\Id\Identity\Contracts\Passkeys;
use Cbox\Id\Identity\Exceptions\ClonedAuthenticator;
use Cbox\Id\Identity\Exceptions\InvalidAssertionResponse;
use Cbox\Id\Identity\Exceptions\UnknownCredential;
use Cbox\Id\Identity\Exceptions\UnsupportedCredential;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Kernel\Crypto\Support\Base64Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * WebAuthn ceremony endpoints. The browser (bundled app.js) calls the *options*
 * routes to get a challenge, runs navigator.credentials, then POSTs the result
 * to the *verify* routes. Cryptographic verification is the framework's
 * (OpenSSL + vetted COSE); this controller only issues challenges and bridges
 * the verified subject into a browser session.
 */
final class PasskeyController extends Controller
{
    private const REG_CHALLENGE = 'passkey.reg_challenge';

    private const AUTH_CHALLENGE = 'passkey.auth_challenge';

    /** Server-side lifetime of an issued challenge, in seconds. */
    private const CHALLENGE_TTL = 120;

    public function registerOptions(Request $request, CurrentUser $me): JsonResponse
    {
        $challenge = random_bytes(32);
        $this->putChallenge($request, self::REG_CHALLENGE, $challenge);

        $existing = WebAuthnCredential::query()
            ->where('user_id', $me->id())
            ->get()
            ->map(fn (WebAuthnCredential $credential): array => ['type' => 'public-key', 'id' => $credential->credential_id])
            ->all();

        return new JsonResponse([
            'challenge' => Base64Url::encode($challenge),
            'rp' => ['id' => $this->rpId(), 'name' => 'Cbox ID'],
            'user' => [
                'id' => Base64Url::encode($me->id()),
                'name' => $me->email() ?? $me->id(),
                'displayName' => $me->name(),
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'authenticatorSelection' => ['residentKey' => 'preferred', 'userVerification' => 'required'],
            'excludeCredentials' => $existing,
            'timeout' => 60000,
            'attestation' => 'none',
        ]);
    }

    public function register(Request $request, CurrentUser $me, Passkeys $passkeys): JsonResponse
    {
        $challenge = $this->pullChallenge($request, self::REG_CHALLENGE);

        if ($challenge === null) {
            return $this->error('Registration challenge expired. Try again.');
        }

        $name = $request->string('name')->toString() ?: 'Passkey';

        try {
            $passkeys->register($me->id(), $challenge, $request->getContent(), $name);
        } catch (InvalidAssertionResponse|UnsupportedCredential $e) {
            return $this->error('That passkey could not be verified.');
        } catch (Throwable) {
            return $this->error('Something went wrong registering that passkey.');
        }

        return new JsonResponse(['ok' => true]);
    }

    public function loginOptions(Request $request): JsonResponse
    {
        $challenge = random_bytes(32);
        $this->putChallenge($request, self::AUTH_CHALLENGE, $challenge);

        return new JsonResponse([
            'challenge' => Base64Url::encode($challenge),
            'rpId' => $this->rpId(),
            'userVerification' => 'required',
            'allowCredentials' => [],
            'timeout' => 60000,
        ]);
    }

    public function login(Request $request, Passkeys $passkeys, PlatformAuth $auth, RiskGuard $risk): JsonResponse
    {
        // Hard-block a Reject before establishing the session. (A passkey is
        // phishing-resistant, so an elevated-but-not-reject outcome needs no step-up.)
        if ($risk->shouldBlock($risk->assess($request, 'login'))) {
            return $this->error('We could not process this request. Please try again later.');
        }

        $challenge = $this->pullChallenge($request, self::AUTH_CHALLENGE);
        $credentialId = $request->string('id')->toString();

        if ($challenge === null || $credentialId === '') {
            return $this->error('Sign-in challenge expired. Try again.');
        }

        try {
            $subjectId = $passkeys->authenticate($credentialId, $challenge, $request->getContent());
        } catch (UnknownCredential) {
            return $this->error('That passkey is not registered.');
        } catch (ClonedAuthenticator) {
            return $this->error('This passkey may have been cloned and was rejected.', 409);
        } catch (InvalidAssertionResponse) {
            return $this->error('That passkey could not be verified.');
        } catch (Throwable) {
            return $this->error('Something went wrong signing in.');
        }

        $auth->establish($request, $subjectId, ['passkey']);

        return new JsonResponse(['redirect' => route('dashboard')]);
    }

    private function putChallenge(Request $request, string $key, string $challenge): void
    {
        // Store the challenge with a server-side expiry so a challenge captured
        // from a stale tab can't be replayed indefinitely (the client `timeout`
        // is advisory only).
        $request->session()->put($key, [
            'c' => base64_encode($challenge),
            'exp' => time() + self::CHALLENGE_TTL,
        ]);
    }

    private function pullChallenge(Request $request, string $key): ?string
    {
        $stored = $request->session()->pull($key);

        if (! is_array($stored) || ! is_string($stored['c'] ?? null) || ! is_int($stored['exp'] ?? null)) {
            return null;
        }

        if ($stored['exp'] < time()) {
            return null; // expired — single-use already enforced by pull()
        }

        $decoded = base64_decode($stored['c'], true);

        return $decoded === false ? null : $decoded;
    }

    private function rpId(): string
    {
        $rpId = config('cbox-id.webauthn.rp_id');

        return is_string($rpId) && $rpId !== '' ? $rpId : 'localhost';
    }

    private function error(string $message, int $status = 422): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
