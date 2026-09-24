<?php

declare(strict_types=1);

namespace App\Http\Props\Console;

use App\Http\Props\Prop;
use App\Platform\Apps\AudienceShape;

/**
 * What `aud` this app's access tokens will carry, worked out from the scopes it holds —
 * the same rule the token endpoint applies, said before the first token is minted.
 *
 * `identifiers` are the registered APIs among the app's scopes; `issuer` is what `aud`
 * is when no API is involved. `withIssuer` is true when a token for the one API also
 * names the issuer, because the app signs people in (`openid`) and UserInfo must keep
 * accepting its tokens. `unowned` are the free-text scopes: they travel as they are, but
 * never on a registered API's audience. `refused` are registered scopes the app holds
 * but may not use — kept from before the API existed, dropped from every token.
 */
final readonly class AppAudienceProps implements Prop
{
    /**
     * @param  list<string>  $identifiers
     * @param  list<string>  $unowned
     * @param  list<string>  $refused
     */
    public function __construct(
        public AudienceShape $shape,
        public string $issuer,
        public array $identifiers,
        public bool $withIssuer,
        public array $unowned,
        public array $refused,
    ) {}

    /**
     * @return array{shape: string, issuer: string, identifiers: list<string>, withIssuer: bool, unowned: list<string>, refused: list<string>}
     */
    public function toArray(): array
    {
        return [
            'shape' => $this->shape->value,
            'issuer' => $this->issuer,
            'identifiers' => $this->identifiers,
            'withIssuer' => $this->withIssuer,
            'unowned' => $this->unowned,
            'refused' => $this->refused,
        ];
    }
}
