<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Actions\Principal\DelegatedTokens;
use App\Platform\Actions\Principal\OAuthDelegatedTokens;
use App\Platform\Actions\Principal\PersonPrincipal;
use Illuminate\Http\Request;

/**
 * Delegated tokens, as a test states them: bearer => the person it speaks for.
 *
 * The real resolver ({@see OAuthDelegatedTokens}) reads OAuth access tokens; this one
 * proves the seam — that the platform and account planes run their actions for whatever
 * principal the resolver hands over, and refuse everything else.
 */
final class FakeDelegatedTokens implements DelegatedTokens
{
    /** @var array<string, PersonPrincipal> */
    private array $tokens = [];

    /**
     * The set bound into the container — this test's, once the first token is stated — so
     * several tokens live side by side.
     */
    public static function install(): self
    {
        $bound = app()->bound(DelegatedTokens::class) ? app(DelegatedTokens::class) : null;

        if ($bound instanceof self) {
            return $bound;
        }

        $tokens = new self;
        app()->instance(DelegatedTokens::class, $tokens);

        return $tokens;
    }

    /**
     * A token a platform operator delegated.
     *
     * @param  list<string>  $scopes
     */
    public function operator(string $bearer, string $operatorId, string $subjectId, array $scopes): self
    {
        $this->tokens[$bearer] = new FakeOperatorToken($operatorId, $subjectId, $scopes);

        return $this;
    }

    /**
     * A token an ordinary person delegated.
     *
     * @param  list<string>  $scopes
     */
    public function person(string $bearer, string $subjectId, array $scopes, ?string $sessionId = null): self
    {
        $this->tokens[$bearer] = new FakePersonToken($subjectId, $scopes, $sessionId);

        return $this;
    }

    public function principal(Request $request): ?PersonPrincipal
    {
        return $this->tokens[(string) $request->bearerToken()] ?? null;
    }
}
