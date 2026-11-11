<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\PageController;
use App\Platform\Operators\OperatorPasskeyLinks;
use App\Platform\Passkeys\PasskeyProviders;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\Passkeys;
use Cbox\Id\Identity\Contracts\RelyingParties;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Crypto\Support\Base64Url;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Throwable;

/**
 * The break-glass enrolment link's three endpoints ({@see OperatorPasskeyLinks}): the
 * screen, the WebAuthn challenge and the registration.
 *
 * Nobody is signed in here, and nothing signs them in. The link's token is the whole
 * authority, re-read on every step; the operator behind it must still be active when the
 * passkey is stored. An unknown, used or expired link is a 404 at every step, so the
 * address says nothing about which operators exist.
 */
final readonly class OperatorPasskeyLinkController extends PageController
{
    private const string CHALLENGE = 'operator.passkey_link.challenge';

    private const int CHALLENGE_TTL = 120;

    public function show(string $token, OperatorPasskeyLinks $links): Response
    {
        $grant = $this->grant($token, $links);

        return $this->page('auth/operator-passkey-link', __('console.operator_passkey_link_title'), [
            'email' => $grant['email'],
            'base' => route('login.direct.passkey', ['token' => $token]),
            'signIn' => route('login.direct'),
            'expiresAt' => date(DATE_ATOM, $grant['expires_at']),
        ]);
    }

    public function options(Request $request, string $token, OperatorPasskeyLinks $links, PlatformRoot $root, RelyingParties $relyingParties): JsonResponse
    {
        $grant = $this->grant($token, $links);

        $subjectId = $grant['subject'];
        $challenge = random_bytes(32);

        $request->session()->put(self::CHALLENGE, [
            'c' => base64_encode($challenge),
            'exp' => time() + self::CHALLENGE_TTL,
            'link' => hash('sha256', $token),
        ]);

        /** @var list<array{type: string, id: string}> $existing */
        $existing = $root->run(static fn (): array => WebAuthnCredential::query()
            ->where('user_id', $subjectId)
            ->get()
            ->map(static fn (WebAuthnCredential $credential): array => ['type' => 'public-key', 'id' => $credential->credential_id])
            ->all());

        return new JsonResponse([
            'challenge' => Base64Url::encode($challenge),
            'rp' => ['id' => $relyingParties->current()->id, 'name' => 'Cbox ID'],
            'user' => [
                'id' => Base64Url::encode($subjectId),
                'name' => $grant['email'],
                'displayName' => $grant['email'],
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

    public function register(
        Request $request,
        string $token,
        OperatorPasskeyLinks $links,
        PlatformRoot $root,
        Passkeys $passkeys,
        PlatformOperators $operators,
        AuditLog $audit,
    ): JsonResponse {
        abort_unless(app(PlatformAuth::class)->onPlatformRoot(), 404);

        $challenge = $this->pullChallenge($request, $token);

        if ($challenge === null) {
            return new JsonResponse(['error' => __('console.operator_attempt_expired')], 422);
        }

        // Spent BEFORE the passkey is stored: of two requests holding the same link, one
        // gets to register. Put back below if the ceremony itself fails.
        $grant = $links->spend($token);

        abort_if($grant === null, 404);

        $subjectId = $grant['subject'];

        // Suspended since the link was issued: the link dies with the authority.
        $operator = $root->run(fn () => $operators->findBySubject($subjectId));

        abort_if($operator === null || (string) $operator->id !== $grant['operator'], 404);

        $name = PasskeyProviders::defaultName($request->getContent(), $request->string('name')->toString());

        try {
            $credential = $root->run(fn (): WebAuthnCredential => $passkeys->register($subjectId, $challenge, $request->getContent(), $name));
        } catch (Throwable) {
            $links->restore($token, $grant);

            return new JsonResponse(['error' => __('console.operator_passkey_not_verified')], 422);
        }

        $root->run(fn () => $audit->record(new AuditEvent(
            action: 'operator.passkey_link.used',
            actorType: ActorType::System,
            actorId: 'cbox-id:operator:passkey-link',
            targetType: 'operator',
            targetId: $grant['operator'],
            context: [
                'subject_id' => $subjectId,
                'credential_id' => $credential instanceof WebAuthnCredential ? (string) $credential->id : null,
                'ip' => $request->ip(),
            ],
        )));

        return new JsonResponse(['ok' => true]);
    }

    /**
     * The live link, on the platform root's own sign-in host only: a passkey is bound to
     * the host it is made on, and an operator's is used where the root signs in.
     *
     * @return array{operator: string, subject: string, email: string, expires_at: int}
     */
    private function grant(string $token, OperatorPasskeyLinks $links): array
    {
        abort_unless(app(PlatformAuth::class)->onPlatformRoot(), 404);

        $grant = $links->peek($token);

        abort_if($grant === null, 404);

        return $grant;
    }

    private function pullChallenge(Request $request, string $token): ?string
    {
        $stored = $request->session()->pull(self::CHALLENGE);

        if (! is_array($stored)
            || ! is_string($stored['c'] ?? null)
            || ! is_int($stored['exp'] ?? null)
            || ! is_string($stored['link'] ?? null)
            || ! hash_equals($stored['link'], hash('sha256', $token))
            || $stored['exp'] < time()) {
            return null;
        }

        $decoded = base64_decode($stored['c'], true);

        return $decoded === false ? null : $decoded;
    }
}
