<?php

declare(strict_types=1);

namespace App\Platform\OAuth;

use App\Platform\OAuth\Enums\AuthorizationPrompt;

/**
 * ONE VALIDATED AUTHORIZATION REQUEST, as the consent screen needs it.
 *
 * Every field here has already been checked against the registered client: the
 * `redirect_uri` is one the client registered, the challenge is a well-formed S256 digest,
 * and the scopes are ones the client may ask for. Nothing on this object is re-derived
 * from the request that approves it, which is the whole point of it existing.
 *
 * Under Volt these were `#[Locked]` component properties, and the attribute was
 * load-bearing: Livewire re-hydrates public properties from the request payload, so
 * without it a browser could swap in an unregistered `redirect_uri` AFTER validation and
 * still have `approve()` mint a code — an open redirect and a code-exfiltration hole. The
 * lock is structural now: this lives in the session, the browser is handed an opaque id,
 * and a value it never holds is a value it cannot swap.
 */
final readonly class PendingAuthorization
{
    /**
     * @param  list<string>  $scopes
     * @param  array<string, mixed>|null  $pushedPayload  the consumed PAR body, kept so a
     *                                                    resumed request can be re-pushed
     * @param  list<AuthorizationPrompt>  $prompts
     */
    public function __construct(
        public string $clientId,
        public string $clientName,
        /**
         * WHO registered this client. The name above is attacker-chosen free text — any
         * organization admin in the environment may register an app called "Cbox ID
         * Account Sync" and point another organization's users at it — so the screen shows
         * provenance the registrant does not control alongside the name they do.
         *
         * Attribution, NOT an access check: cross-organization consent is deliberate (a
         * genuine multi-tenant app is registered by one organization and used by many), so
         * the answer is to tell the person who they are trusting, not to refuse.
         */
        public string $clientOwner,
        public string $redirectUri,
        public array $scopes,
        public string $codeChallenge,
        public string $codeChallengeMethod,
        public ?string $state = null,
        public ?string $nonce = null,
        /**
         * The RFC 8707 resource indicator this authorization is for, as the client asked at
         * `/authorize`. It is bound to the issued code, so a client that could edit it
         * between render and approval would be choosing its own audience again.
         */
        public ?string $resource = null,
        /** OIDC Core §3.1.2.1 `max_age`, re-asserted at issue time. */
        public ?int $maxAge = null,
        /** The raw OIDC `acr_values` request, for the same reason. */
        public ?string $acrValues = null,
        public ?array $pushedPayload = null,
        /**
         * THE ORGANIZATION THIS GRANT IS BOUND TO, once one has been decided: named by the
         * client (`organization`), chosen on the hosted picker, or founded on the hosted
         * create step. Null means "the organization the person's session is in", which is
         * what every authorization meant before an app could choose.
         *
         * Held here, server-side, for the same reason as the redirect URI: a value the
         * browser never holds is a value it cannot swap between the picker and the code.
         */
        public ?string $organizationId = null,
        /** `organization_hint`: preselected on the picker, never bound on its own. */
        public ?string $organizationHint = null,
        /**
         * The recognised `prompt` values, kept so the organization steps and a forced
         * consent survive the pages in between.
         */
        public array $prompts = [],
    ) {}

    public function asks(AuthorizationPrompt $prompt): bool
    {
        return in_array($prompt, $this->prompts, true);
    }

    /** The same request, bound to an organization — what the picker and the create step produce. */
    public function boundTo(string $organizationId): self
    {
        return new self(
            clientId: $this->clientId,
            clientName: $this->clientName,
            clientOwner: $this->clientOwner,
            redirectUri: $this->redirectUri,
            scopes: $this->scopes,
            codeChallenge: $this->codeChallenge,
            codeChallengeMethod: $this->codeChallengeMethod,
            state: $this->state,
            nonce: $this->nonce,
            resource: $this->resource,
            maxAge: $this->maxAge,
            acrValues: $this->acrValues,
            pushedPayload: $this->pushedPayload,
            organizationId: $organizationId,
            organizationHint: $this->organizationHint,
            prompts: $this->prompts,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        foreach (['clientId', 'clientName', 'clientOwner', 'redirectUri', 'codeChallenge', 'codeChallengeMethod'] as $required) {
            if (! is_string($data[$required] ?? null) || $data[$required] === '') {
                return null;
            }
        }

        /** @var list<string> $scopes */
        $scopes = array_values(array_filter(
            is_array($data['scopes'] ?? null) ? $data['scopes'] : [],
            static fn (mixed $scope): bool => is_string($scope),
        ));

        return new self(
            clientId: (string) $data['clientId'],
            clientName: (string) $data['clientName'],
            clientOwner: (string) $data['clientOwner'],
            redirectUri: (string) $data['redirectUri'],
            scopes: $scopes,
            codeChallenge: (string) $data['codeChallenge'],
            codeChallengeMethod: (string) $data['codeChallengeMethod'],
            state: is_string($data['state'] ?? null) ? $data['state'] : null,
            nonce: is_string($data['nonce'] ?? null) ? $data['nonce'] : null,
            resource: is_string($data['resource'] ?? null) ? $data['resource'] : null,
            maxAge: is_int($data['maxAge'] ?? null) ? $data['maxAge'] : null,
            acrValues: is_string($data['acrValues'] ?? null) ? $data['acrValues'] : null,
            pushedPayload: self::payload($data['pushedPayload'] ?? null),
            organizationId: is_string($data['organizationId'] ?? null) ? $data['organizationId'] : null,
            organizationHint: is_string($data['organizationHint'] ?? null) ? $data['organizationHint'] : null,
            prompts: AuthorizationPrompt::parse(implode(' ', array_filter(
                is_array($data['prompts'] ?? null) ? $data['prompts'] : [],
                static fn (mixed $prompt): bool => is_string($prompt),
            ))),
        );
    }

    /**
     * The consumed PAR body, with its keys narrowed to strings.
     *
     * Narrowed rather than trusted: it arrives from the session, and it is re-pushed
     * verbatim when a request has to be resumed — so what goes back out has to be the
     * shape the pusher accepts.
     *
     * @return array<string, mixed>|null
     */
    private static function payload(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        $payload = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $payload[$key] = $item;
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'clientId' => $this->clientId,
            'clientName' => $this->clientName,
            'clientOwner' => $this->clientOwner,
            'redirectUri' => $this->redirectUri,
            'scopes' => $this->scopes,
            'codeChallenge' => $this->codeChallenge,
            'codeChallengeMethod' => $this->codeChallengeMethod,
            'state' => $this->state,
            'nonce' => $this->nonce,
            'resource' => $this->resource,
            'maxAge' => $this->maxAge,
            'acrValues' => $this->acrValues,
            'pushedPayload' => $this->pushedPayload,
            'organizationId' => $this->organizationId,
            'organizationHint' => $this->organizationHint,
            'prompts' => array_map(static fn (AuthorizationPrompt $prompt): string => $prompt->value, $this->prompts),
        ];
    }
}
