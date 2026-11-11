<?php

declare(strict_types=1);

namespace App\Platform\ConsoleSignIn;

use App\Platform\IntendedUrl;
use App\Platform\SignInLineage;
use App\Platform\SignInThrough;
use App\Platform\StepUpPrompt;
use App\Platform\Surfaces\Surface;
use App\Platform\Surfaces\Surfaces;
use Cbox\Id\Kernel\Crypto\Contracts\TokenSigner;
use Cbox\Id\Kernel\Crypto\Enums\SigningAlg;
use Cbox\Id\Kernel\Crypto\Exceptions\InvalidToken;
use Cbox\Id\Kernel\Crypto\ValueObjects\TokenClaims;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * THE CONSOLE AS A RELYING PARTY of the login host (login-host mode only).
 *
 * The app host serves no sign-in page: `/login` is a 404 there. An unauthenticated console
 * request is sent to `/auth/start`, which sends the browser to `{login host}/oauth/authorize`
 * as the console's own client ({@see ConsoleClient}) with PKCE, `state` and `nonce`; the
 * login host signs the person in (or finds them signed in) and returns a code to
 * `/auth/callback`, where the console redeems it, verifies the ID token and starts a session
 * of its own ({@see ConsoleSession}).
 *
 * NOT {@see SignInThrough} and not the federation callback: those are an
 * environment's setting for signing in through SOMEBODY ELSE's identity provider, resolved
 * through federated-identity rows. Here the issuer is this deployment's own default
 * environment, so `sub` already is the person's row and nothing is linked or provisioned.
 *
 * The same round trip is how the console asks for a step-up ({@see ConsoleStepUps}): the
 * request then carries `max_age` and, where the plane names one, `acr_values`, and the
 * console opens its sudo window from the `auth_time` and `acr` that come back.
 *
 * In single-host mode {@see active()} is false and nothing here is reached.
 */
final class ConsoleSignIn
{
    /** Where the pending authorization requests are kept in the console's session, by `state`. */
    public const string SESSION_KEY = 'cbox.console_sign_in';

    /** How long a browser may take at the login host before its `state` is forgotten. */
    private const int TTL_SECONDS = 900;

    /** A browser has a handful of tabs mid-flight at most; the oldest gives way. */
    private const int MAX_PENDING = 6;

    private const array ID_TOKEN_ALGS = [SigningAlg::RS256, SigningAlg::ES256, SigningAlg::EdDSA];

    public function __construct(
        private readonly Surfaces $surfaces,
        private readonly ConsoleClient $client,
        private readonly IssuerTokens $tokens,
        private readonly TokenSigner $signer,
        private readonly PlatformRoot $root,
        private readonly IssuerResolver $issuers,
    ) {}

    /** Whether this request is the console in login-host mode. */
    public function active(?Request $request = null): bool
    {
        return $this->surfaces->current($request) === Surface::Console;
    }

    /**
     * The console's door: `/auth/start` on the app host.
     *
     * @param  array<string, scalar|null>  $query
     */
    public function startUrl(array $query = []): string
    {
        return route('console.auth.start', array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    /**
     * RP-INITIATED LOGOUT: where the browser goes after the console has ended its own
     * sessions, so the login host ends this browser's SSO session too and sends it back to
     * the console's "You're signed out" page. Null until the client is provisioned.
     *
     * By `client_id`, with no `id_token_hint`: a hint is the login host's proof for ending
     * EVERY session the person has, and "Log out" in the console is this browser's sign-in
     * and no other device's ({@see SignInLineage}).
     */
    public function signOutUrl(): ?string
    {
        $client = $this->client->find();

        return $client === null ? null : $this->surfaces->loginUrl('/oauth/logout', [
            'client_id' => $client->client_id,
            'post_logout_redirect_uri' => $this->client->postLogoutRedirectUri(),
        ]);
    }

    /**
     * Send an unauthenticated console request to the door, in a way the caller can act on:
     * a redirect for a navigation, a 401 naming the door for a request a script made. The
     * page to come back to is kept in the session either way (`url.intended`, the key
     * {@see IntendedUrl} reads).
     */
    public function refuse(Request $request): Response
    {
        $intended = $request->isMethod('GET') && ! $request->expectsJson()
            ? $request->fullUrl()
            : StepUpPrompt::sameOriginPath($request, $request->headers->get('referer'));

        if ($intended !== null) {
            $request->session()->put('url.intended', $intended);
        }

        // An Inertia visit is an XHR, and an XHR cannot follow a redirect to another
        // origin: it is told to leave the page instead (409, `X-Inertia-Location`).
        if ($request->header('X-Inertia') !== null) {
            return Inertia::location($this->startUrl());
        }

        if ($request->expectsJson()) {
            return new JsonResponse(['message' => 'Your session has expired.', 'redirect' => $this->startUrl()], 401);
        }

        return redirect()->to($this->startUrl());
    }

    /**
     * Record an authorization request in the session and answer the URL the browser is
     * sent to at the login host.
     *
     * @throws ConsoleSignInFailed when the console's client has not been provisioned
     */
    public function begin(Request $request, ?ConsoleStepUpRequest $stepUp = null, bool $popup = false): string
    {
        $client = $this->client->find();

        if ($client === null || $this->client->secret() === null) {
            throw ConsoleSignInFailed::because('client_not_provisioned');
        }

        $state = Str::random(48);
        $pending = new PendingSignIn(
            verifier: Str::random(64),
            nonce: Str::random(32),
            startedAt: now()->getTimestamp(),
            plane: $stepUp?->plane,
            acr: $stepUp?->acr,
            maxAge: $stepUp?->maxAge,
            environment: $stepUp?->environment,
            popup: $popup,
            organization: $stepUp?->organization,
        );

        $held = $this->held($request);
        $held[$state] = $pending->toArray();
        $request->session()->put(self::SESSION_KEY, array_slice($held, -self::MAX_PENDING, null, true));

        return $this->surfaces->loginUrl('/oauth/authorize', array_filter([
            'response_type' => 'code',
            'client_id' => $client->client_id,
            'redirect_uri' => $this->client->redirectUri(),
            'scope' => implode(' ', ConsoleClient::SCOPES),
            'state' => $state,
            'nonce' => $pending->nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $pending->verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'max_age' => $stepUp?->maxAge,
            'acr_values' => $stepUp?->acr,
            'organization' => $stepUp?->organization,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * The pending request a returning browser's `state` names, spent by the asking: a
     * `state` answers once.
     */
    public function claim(Request $request): ?PendingSignIn
    {
        $state = $request->query('state');
        $held = $this->held($request);

        if (! is_string($state) || $state === '' || ! isset($held[$state])) {
            return null;
        }

        $pending = PendingSignIn::fromArray($held[$state]);
        unset($held[$state]);
        $request->session()->put(self::SESSION_KEY, $held);

        return $pending;
    }

    /**
     * Redeem the code the login host returned and verify what it says.
     *
     * @throws ConsoleSignInFailed
     */
    public function redeem(Request $request, PendingSignIn $pending): ConsoleIdentity
    {
        $code = $request->query('code');
        $client = $this->client->find();
        $secret = $this->client->secret();

        if (! is_string($code) || $code === '') {
            throw ConsoleSignInFailed::because('no_code');
        }

        if ($client === null || $secret === null) {
            throw ConsoleSignInFailed::because('client_not_provisioned');
        }

        $idToken = $this->tokens->exchange($client->client_id, $secret, $code, $pending->verifier, $this->client->redirectUri());

        return $this->verify($idToken, $client->client_id, $pending->nonce);
    }

    /**
     * The ID token, checked against this deployment's own keys in the default environment:
     * signature and expiry, that the login host issued it, that it is for the console's
     * client, and that it answers the request this browser left with.
     */
    private function verify(string $idToken, string $clientId, string $nonce): ConsoleIdentity
    {
        try {
            $claims = $this->root->run(fn (): TokenClaims => $this->signer->verify($idToken, self::ID_TOKEN_ALGS));
        } catch (InvalidToken) {
            throw ConsoleSignInFailed::because('id_token_invalid');
        }

        $issuer = $this->root->run(fn (): string => rtrim($this->issuers->issuer(), '/'));

        if ($claims === null || $issuer === null) {
            throw ConsoleSignInFailed::because('id_token_invalid');
        }

        $audience = $claims->get('aud');
        $audiences = is_string($audience) ? [$audience] : (is_array($audience) ? $audience : []);
        $subject = $claims->subject();
        $held = $claims->string('nonce');

        if (rtrim((string) $claims->string('iss'), '/') !== $issuer) {
            throw ConsoleSignInFailed::because('id_token_issuer');
        }

        if (! in_array($clientId, $audiences, true)) {
            throw ConsoleSignInFailed::because('id_token_audience');
        }

        if ($held === null || ! hash_equals($nonce, $held)) {
            throw ConsoleSignInFailed::because('id_token_nonce');
        }

        if ($subject === null || $subject === '') {
            throw ConsoleSignInFailed::because('id_token_subject');
        }

        $authTime = $claims->get('auth_time');
        $amr = $claims->get('amr');

        return new ConsoleIdentity(
            subjectId: $subject,
            sid: $claims->string('sid'),
            authTime: is_int($authTime) ? $authTime : null,
            acr: $claims->string('acr'),
            amr: is_array($amr) ? array_values(array_filter($amr, is_string(...))) : [],
            organizationId: $claims->string('org'),
        );
    }

    /**
     * The pending requests still in time, oldest first.
     *
     * @return array<array-key, mixed>
     */
    private function held(Request $request): array
    {
        $held = $request->session()->get(self::SESSION_KEY);
        $cutoff = now()->getTimestamp() - self::TTL_SECONDS;

        return array_filter(
            is_array($held) ? $held : [],
            static fn (mixed $entry): bool => is_array($entry) && is_int($entry['at'] ?? null) && $entry['at'] >= $cutoff,
        );
    }
}
