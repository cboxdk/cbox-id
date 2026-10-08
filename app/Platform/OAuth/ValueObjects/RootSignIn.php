<?php

declare(strict_types=1);

namespace App\Platform\OAuth\ValueObjects;

use App\Platform\OAuth\RootDelegatedAccess;

/**
 * What a valid root-host access token says, once {@see RootDelegatedAccess} has vouched for
 * it: the person of the PLATFORM ROOT it stands for, the client they signed in, and the
 * scopes they handed it. Facts about the token only — what the person may DO with it is
 * asked of their workspace membership and the operator roster, now, by the principal built
 * from this.
 */
final readonly class RootSignIn
{
    /**
     * @param  list<string>  $scopes  every scope the token carries, protocol ones included
     * @param  string|null  $organizationId  the token's `org` claim: the workspace it was bound to, if any
     */
    public function __construct(
        public string $subjectId,
        public string $personName,
        public string $clientId,
        public string $clientName,
        public array $scopes,
        public ?string $organizationId = null,
        public ?int $expiresAt = null,
    ) {}

    public function grants(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * The person AND the client, so two agents one person signed in never share an
     * idempotency key or an approval — the same identity {@see DelegatedTokenPrincipal}
     * gives a token of an environment. The client id is hashed because a metadata document
     * client's is a URL, and this id is stored beside the kind in a 100-character column.
     */
    public function principalId(): string
    {
        return $this->subjectId.':'.substr(hash('sha256', $this->clientId), 0, 32);
    }
}
