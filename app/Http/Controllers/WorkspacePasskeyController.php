<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\AccountAuth;
use App\Platform\Enums\CredentialVerdict;
use App\Platform\Enums\RefusedFactor;
use App\Platform\SsoRefusal;
use Cbox\Id\Identity\Contracts\RelyingParties;
use Cbox\Id\Identity\Exceptions\ClonedAuthenticator;
use Cbox\Id\Identity\Exceptions\InvalidAssertionResponse;
use Cbox\Id\Identity\Exceptions\UnknownCredential;
use Cbox\Id\Identity\Exceptions\UnsupportedCredential;
use Cbox\Id\Kernel\Crypto\Support\Base64Url;
use Cbox\Id\Platform\Contracts\AccountPasskeys;
use Cbox\Id\Platform\Models\AccountWebAuthnCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * WebAuthn ceremony for the account (buyer) plane — the strongest factor for the
 * members who own customer IdPs. Registration is gated on the current member;
 * sign-in is usernameless (discoverable credential → the assertion's credential id
 * identifies the member). All cryptography is the framework's shared verifier; this
 * controller only issues challenges and bridges the verified member into a session.
 */
final class WorkspacePasskeyController extends Controller
{
    private const REG_CHALLENGE = 'workspace.passkey.reg_challenge';

    private const AUTH_CHALLENGE = 'workspace.passkey.auth_challenge';

    private const CHALLENGE_TTL = 120;

    public function registerOptions(Request $request, AccountAuth $auth, AccountPasskeys $passkeys): JsonResponse
    {
        $member = $auth->current();

        if ($member === null) {
            return $this->error('Not signed in.', 401);
        }

        $challenge = random_bytes(32);
        $this->putChallenge($request, self::REG_CHALLENGE, $challenge);

        $existing = $passkeys->forMember($member->id)
            ->map(fn (AccountWebAuthnCredential $c): array => ['type' => 'public-key', 'id' => $c->credential_id])
            ->all();

        $brand = config('cbox-id.branding.name', 'Cbox ID');

        return new JsonResponse([
            'challenge' => Base64Url::encode($challenge),
            'rp' => ['id' => $this->rpId(), 'name' => is_string($brand) ? $brand : 'Cbox ID'],
            'user' => [
                'id' => Base64Url::encode($member->id),
                'name' => $member->email,
                'displayName' => $member->name ?? $member->email,
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'authenticatorSelection' => ['residentKey' => 'required', 'userVerification' => 'required'],
            'excludeCredentials' => $existing,
            'timeout' => 60000,
            'attestation' => 'none',
        ]);
    }

    public function register(Request $request, AccountAuth $auth, AccountPasskeys $passkeys): JsonResponse
    {
        $member = $auth->current();

        if ($member === null) {
            return $this->error('Not signed in.', 401);
        }

        $challenge = $this->pullChallenge($request, self::REG_CHALLENGE);

        if ($challenge === null) {
            return $this->error('Registration challenge expired. Try again.');
        }

        $name = $request->string('name')->toString() ?: 'Passkey';

        try {
            $passkeys->register($member->id, $challenge, $request->getContent(), $name);
        } catch (InvalidAssertionResponse|UnsupportedCredential) {
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

    public function login(Request $request, AccountPasskeys $passkeys, AccountAuth $auth): JsonResponse
    {
        $challenge = $this->pullChallenge($request, self::AUTH_CHALLENGE);
        $credentialId = $request->string('id')->toString();

        if ($challenge === null || $credentialId === '') {
            return $this->error('Sign-in challenge expired. Try again.');
        }

        try {
            $memberId = $passkeys->authenticate($credentialId, $challenge, $request->getContent());
        } catch (UnknownCredential) {
            return $this->error('That passkey is not registered.');
        } catch (ClonedAuthenticator) {
            return $this->error('This passkey may have been cloned and was rejected.', 409);
        } catch (InvalidAssertionResponse) {
            return $this->error('That passkey could not be verified.');
        } catch (Throwable) {
            return $this->error('Something went wrong signing in.');
        }

        // The same decision as the tenant passkey door, one plane up, and it has to be the
        // same one: an account member IS an ordinary subject in the platform root, so an
        // organization that mandates SSO and is honoured on the tenant plane but not here
        // has not mandated anything — it has moved the bypass to the console that owns the
        // billing. The argument for refusing a phishing-resistant factor is in
        // {@see \App\Platform\MemberCredentialGate::admitsFactor()}.
        //
        // Asked after the assertion verifies, so it is never an existence oracle. The
        // member id comes FROM the assertion; nobody who has not signed the challenge with
        // a registered credential ever reaches this line.
        if ($auth->admitsFactor($memberId) === CredentialVerdict::SsoRequired) {
            // Non-null by construction — the gate can only have reached that verdict
            // through the member's subject — but this is a second read, and a nullsafe
            // hold costs less than a promise about the first one.
            $subjectId = $auth->subjectFor($memberId);

            if ($subjectId !== null) {
                SsoRefusal::hold($subjectId, RefusedFactor::Passkey);
            }

            return new JsonResponse(['redirect' => route('workspace.login')]);
        }

        // A passkey is phishing-resistant strong auth — it establishes the session
        // directly, no second factor needed.
        $auth->establish($memberId, ['passkey']);

        return new JsonResponse(['redirect' => route('workspace.home')]);
    }

    private function putChallenge(Request $request, string $key, string $challenge): void
    {
        $request->session()->put($key, ['c' => base64_encode($challenge), 'exp' => time() + self::CHALLENGE_TTL]);
    }

    private function pullChallenge(Request $request, string $key): ?string
    {
        $stored = $request->session()->pull($key);

        if (! is_array($stored) || ! is_string($stored['c'] ?? null) || ! is_int($stored['exp'] ?? null)) {
            return null;
        }

        if ($stored['exp'] < time()) {
            return null;
        }

        $decoded = base64_decode($stored['c'], true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * The RP id the challenge is issued under — resolved per request from the environment
     * this host serves, exactly as the verifier resolves the pair it checks against.
     *
     * Read straight from `cbox-id.webauthn.rp_id` before, which is one deployment-wide
     * value: on any host but the one it named, the browser scoped the credential to a
     * different RP than the verifier would accept, and the ceremony could only fail.
     */
    private function rpId(): string
    {
        return app(RelyingParties::class)->current()->id;
    }

    private function error(string $message, int $status = 422): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
