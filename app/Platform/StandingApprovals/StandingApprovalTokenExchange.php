<?php

declare(strict_types=1);

namespace App\Platform\StandingApprovals;

use Cbox\Id\OAuthServer\Contracts\TokenExchange;
use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\Exceptions\InvalidTokenExchange;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\AuthorizationDetails;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\OAuthServer\ValueObjects\TokenExchangeRequest;
use Cbox\Id\OAuthServer\ValueObjects\TokenExchangeResult;

/**
 * THE TOKEN EXCHANGE, WITH ONE MORE SUBJECT IT ACCEPTS: a machine client covered by a
 * standing approval ({@see StandingApprovals}).
 *
 * The framework's exchange issues only for a PERSON: the new token is minted for the subject
 * token's user, and a client-credentials token has none, so a deployment a machine proposed
 * from CI could never get the plan-bound token the app's engine runs it with. That stays true
 * for every request but one shape, handled here and nowhere else:
 *
 *  - the subject token is active, a client's own (client credentials: `sub` is its
 *    `client_id`), acts for nobody, and names an organization;
 *  - the request asks for exactly one `cbox_infra_change` entry, saying
 *    `"approval": "standing_grant"` and naming the plan digest, the app's environment and
 *    its kind;
 *  - the exchanging app is registered for that type, and an active standing approval covers
 *    that client, that app and that environment, of that kind, never production-class.
 *
 * Then the app gets a token minted for the machine subject ({@see StandingApprovalTokenIssuer}),
 * whose entry this server writes itself — the digest, the change plan, the environment and
 * kind as granted, the grant's id and the client — so nothing the caller sent beyond the
 * digest is taken on trust. Every other request, a person's included, goes to the framework
 * exactly as before; a machine subject that matches none of this is refused as it always was.
 */
final readonly class StandingApprovalTokenExchange implements TokenExchange
{
    /** A plan digest: SHA-256, as hex. */
    private const string DIGEST_PATTERN = '/^[a-f0-9]{64}$/D';

    public function __construct(
        private TokenExchange $inner,
        private TokenIntrospector $introspector,
        private StandingApprovals $approvals,
        private StandingApprovalTokenIssuer $issuer,
    ) {}

    public function exchange(Client $client, TokenExchangeRequest $request): TokenExchangeResult
    {
        $entry = self::standingEntry($request->authorizationDetails);

        if ($entry === null || $request->subjectTokenType !== TokenExchangeRequest::ACCESS_TOKEN_TYPE) {
            return $this->inner->exchange($client, $request);
        }

        $subject = $this->introspector->introspect($request->subjectToken);

        // A person's token keeps the person's path: a standing approval is for machines only,
        // and the framework already binds a person's approval to the plan.
        if (! self::isMachine($subject)) {
            return $this->inner->exchange($client, $request);
        }

        return $this->forMachine($client, $request, $subject, $entry);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function forMachine(Client $client, TokenExchangeRequest $request, Introspection $subject, array $entry): TokenExchangeResult
    {
        if (array_key_exists('act', $subject->claims)) {
            throw InvalidTokenExchange::actedSubject();
        }

        if ($subject->confirmationThumbprint() !== null) {
            // A DPoP-bound CI token would need the caller to prove the CI's key, which the app
            // cannot; nothing in the CI flow binds one, so the answer is a plain no.
            throw InvalidTokenExchange::unprovenSenderConstraint();
        }

        if ($request->requestedTokenType !== null && $request->requestedTokenType !== TokenExchangeRequest::ACCESS_TOKEN_TYPE) {
            throw InvalidTokenExchange::unsupportedRequestedType($request->requestedTokenType);
        }

        if (! $client->allowsAuthorizationDetailsType(StandingApprovals::DETAILS_TYPE)) {
            throw InvalidTokenExchange::invalidAuthorizationDetails('The client is not registered for authorization_details type ['.StandingApprovals::DETAILS_TYPE.'].');
        }

        $organizationId = $subject->claims['org'] ?? null;
        $machineId = (string) $subject->clientId;
        $digest = self::string($entry, 'plan_digest');
        $environmentRef = self::string($entry, 'environment_id');
        $kind = mb_strtolower(self::string($entry, 'environment_kind'));

        if (! is_string($organizationId) || $organizationId === '' || preg_match(self::DIGEST_PATTERN, $digest) !== 1 || $environmentRef === '' || $kind === '') {
            throw InvalidTokenExchange::invalidAuthorizationDetails('A standing approval names the plan digest, the environment and its kind, for a client of an organization.');
        }

        if (StandingApprovals::productionClass($kind)) {
            throw new InvalidTokenExchange('invalid_grant', 'A production environment never carries a standing approval: a person approves its deployments.');
        }

        $grant = $this->approvals->covering($organizationId, $machineId, $client->client_id, $environmentRef);

        if ($grant === null || $grant->environment_kind !== $kind || StandingApprovals::productionClass($grant->environment_kind)) {
            throw new InvalidTokenExchange('invalid_grant', 'No standing approval covers this client\'s deployment to that environment.');
        }

        $details = AuthorizationDetails::fromRequest([array_filter([
            'type' => StandingApprovals::DETAILS_TYPE,
            'plan_digest' => $digest,
            'change_plan_id' => self::string($entry, 'change_plan_id') ?: null,
            'approval' => StandingApprovals::METHOD,
            'standing_grant_id' => $grant->id,
            'client_id' => $machineId,
            'environment_id' => $grant->environment_ref,
            'environment_kind' => $grant->environment_kind,
        ], static fn (?string $value): bool => $value !== null)]);

        $issued = $this->issuer->issue($client, $machineId, $organizationId, $details, $request->resource);
        $this->approvals->used($grant, $digest, $issued->jti);

        return new TokenExchangeResult($issued, $issued->scopes, $details);
    }

    /**
     * The request's one `cbox_infra_change` entry asking for a standing approval, or null when
     * the request is anything else. More than one entry is not a standing approval's request.
     *
     * @return array<string, mixed>|null
     */
    private static function standingEntry(?AuthorizationDetails $details): ?array
    {
        if ($details === null) {
            return null;
        }

        $entries = $details->entries;

        if (count($entries) !== 1) {
            return null;
        }

        $entry = $entries[0];

        if (($entry['type'] ?? null) !== StandingApprovals::DETAILS_TYPE || ($entry['approval'] ?? null) !== StandingApprovals::METHOD) {
            return null;
        }

        return $entry;
    }

    /** A client's own token: active, and its subject is the client itself. */
    private static function isMachine(Introspection $subject): bool
    {
        return $subject->active
            && is_string($subject->clientId) && $subject->clientId !== ''
            && $subject->subject === $subject->clientId;
    }

    /** @param array<string, mixed> $entry */
    private static function string(array $entry, string $key): string
    {
        $value = $entry[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }
}
