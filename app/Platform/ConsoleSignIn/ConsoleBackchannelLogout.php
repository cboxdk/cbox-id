<?php

declare(strict_types=1);

namespace App\Platform\ConsoleSignIn;

use App\Platform\SignInLineage;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Kernel\Crypto\Contracts\TokenSigner;
use Cbox\Id\Kernel\Crypto\Enums\SigningAlg;
use Cbox\Id\Kernel\Crypto\Exceptions\InvalidToken;
use Cbox\Id\Kernel\Crypto\ValueObjects\TokenClaims;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\JwtLogoutTokenIssuer;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\DB;

/**
 * OIDC BACK-CHANNEL LOGOUT, received by the console: the login host says an SSO session
 * ended, and the console sessions handed down from it end with it.
 *
 * The logout token is verified like the ID token: this deployment's own keys in the
 * default environment, the login host as issuer, the console's client as audience, the
 * back-channel logout event present and no `nonce` (OIDC Back-Channel Logout 1.0 §2.6).
 *
 *  - With a `sid`: the SSO session row it names, and its family ({@see SignInLineage::family()}).
 *  - With a `sub` alone ("signed out everywhere"): every console session of that person.
 *
 * Idempotent: a token delivered twice finds the rows already ended.
 */
final readonly class ConsoleBackchannelLogout
{
    private const array ALGS = [SigningAlg::RS256, SigningAlg::ES256, SigningAlg::EdDSA];

    public function __construct(
        private ConsoleClient $client,
        private ConsoleSession $sessions,
        private SignInLineage $lineage,
        private TokenSigner $signer,
        private IssuerResolver $issuers,
        private PlatformRoot $root,
    ) {}

    /** Act on a logout token. False when it is not one the console accepts. */
    public function receive(string $logoutToken): bool
    {
        $client = $this->client->find();

        if ($client === null) {
            return false;
        }

        try {
            $claims = $this->root->run(fn (): TokenClaims => $this->signer->verify($logoutToken, self::ALGS));
        } catch (InvalidToken) {
            return false;
        }

        $issuer = $this->root->run(fn (): string => rtrim($this->issuers->issuer(), '/'));

        if ($claims === null || $issuer === null) {
            return false;
        }

        $audience = $claims->get('aud');
        $audiences = is_string($audience) ? [$audience] : (is_array($audience) ? $audience : []);
        $events = $claims->get('events');
        $events = is_object($events) ? (array) $events : $events;
        $subject = $claims->subject();
        $sid = $claims->string('sid');

        if (rtrim((string) $claims->string('iss'), '/') !== $issuer
            || ! in_array($client->client_id, $audiences, true)
            || ! is_array($events) || ! array_key_exists(JwtLogoutTokenIssuer::EVENT, $events)
            || $claims->has('nonce')
            || ($subject === null && $sid === null)) {
            return false;
        }

        $ended = $this->root->run(fn (): array => $sid !== null
            ? $this->familyOf($subject, $sid)
            : $this->consoleSessionsOf((string) $subject));

        $this->lineage->revoke($ended ?? []);

        return true;
    }

    /**
     * The SSO session a `sid` names and everything handed down from it.
     *
     * @return list<string>
     */
    private function familyOf(?string $subject, string $sid): array
    {
        $parent = $subject !== null
            ? $this->sessions->sessionWithSid($subject, $sid)?->id
            : null;

        return $parent === null ? [] : $this->lineage->family([$parent]);
    }

    /**
     * Every session of this person that was handed down from another: their console sessions.
     *
     * @return list<string>
     */
    private function consoleSessionsOf(string $subject): array
    {
        return array_values(Session::query()
            ->where('user_id', $subject)
            ->whereNull('revoked_at')
            ->whereIn('id', DB::table(SignInLineage::TABLE)->select('session_id'))
            ->pluck('id')
            ->map(static fn (mixed $id): string => is_string($id) ? $id : '')
            ->filter()
            ->all());
    }
}
