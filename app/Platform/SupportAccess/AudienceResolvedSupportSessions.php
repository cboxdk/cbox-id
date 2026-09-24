<?php

declare(strict_types=1);

namespace App\Platform\SupportAccess;

use Cbox\Id\OAuthServer\Contracts\SupportSessions;
use Cbox\Id\OAuthServer\Exceptions\InvalidAudience;
use Cbox\Id\OAuthServer\Models\SupportSession;
use Cbox\Id\OAuthServer\ValueObjects\NewSupportSession;
use Cbox\Id\OAuthServer\ValueObjects\StartedSupportSession;
use Cbox\Id\OAuthServer\ValueObjects\SupportCodeRequest;

/**
 * {@see SupportSessions}, with a session's scopes settled BEFORE it starts to exactly what
 * the tokens it mints will carry ({@see SupportSessionScopes} says why they differed).
 *
 * A decorator rather than a step each caller remembers, so the management API, the
 * environment console and anything added later all store, mint codes for and report the
 * same set the token endpoint grants. A set that cannot be audienced at all is refused
 * here, rather than starting a session, telling the customer about it, and minting codes
 * no token endpoint will redeem.
 */
final readonly class AudienceResolvedSupportSessions implements SupportSessions
{
    public function __construct(
        private SupportSessions $inner,
        private SupportSessionScopes $scopes,
    ) {}

    /**
     * @throws InvalidAudience when the session's scopes cannot be audienced to one API
     */
    public function begin(NewSupportSession $request, ?SupportCodeRequest $code = null): StartedSupportSession
    {
        return $this->inner->begin($this->settled($request), $code);
    }

    public function issueCode(string $sessionId, string $actorId, SupportCodeRequest $code): string
    {
        return $this->inner->issueCode($sessionId, $actorId, $code);
    }

    public function active(string $sessionId): ?SupportSession
    {
        return $this->inner->active($sessionId);
    }

    public function openFor(string $actorId): array
    {
        return $this->inner->openFor($actorId);
    }

    public function end(string $sessionId, ?string $endedBy = null): void
    {
        $this->inner->end($sessionId, $endedBy);
    }

    /**
     * The request with its scopes replaced by what a token would carry — or untouched when
     * there is nothing to settle: an app the framework will refuse (so the refusal names
     * the real reason), or an empty set, which must not be handed on as "nothing asked"
     * because the framework reads that as "the app's whole registration".
     */
    private function settled(NewSupportSession $request): NewSupportSession
    {
        $scopes = $this->scopes->settle($request->clientId, $request->scopes);

        if ($scopes === null || $scopes === []) {
            return $request;
        }

        return new NewSupportSession(
            actorId: $request->actorId,
            actorKind: $request->actorKind,
            targetUserId: $request->targetUserId,
            organizationId: $request->organizationId,
            clientId: $request->clientId,
            reason: $request->reason,
            scopes: $scopes,
            ttlSeconds: $request->ttlSeconds,
        );
    }
}
