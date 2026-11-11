<?php

declare(strict_types=1);

namespace App\Platform\Api;

use App\Platform\Keys\OrganizationKeyPrefixes;
use Cbox\Id\Kernel\Tenancy\Contracts\Environment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentResolver;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\Kernel\Tenancy\Scopes\EnvironmentScope;
use Cbox\Id\Organization\Models\Environment as EnvironmentModel;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\DB;

/**
 * Which environment a credential belongs to, read from the credential and never from the
 * host it was presented on.
 *
 *  - a `cbid_env_` key: the environment its row belongs to;
 *  - an access token: the environment whose issuer `iss` names, matched EXACTLY. Nothing in
 *    the token is trusted beyond that lookup: the token is then verified against that one
 *    environment's keys by the door that admits it (R3: never verify against "any" key);
 *  - an organization key: none, it acts above every environment.
 */
final readonly class CredentialEnvironment
{
    public const string ENVIRONMENT_KEY_PREFIX = 'cbid_env_';

    public function __construct(
        private EnvironmentResolver $environments,
        private IssuerResolver $issuers,
        private PlatformRoot $root,
    ) {}

    /** What kind of credential this is: `environment_key`, `organization_key`, `token` or `none`. */
    public static function kind(?string $bearer): string
    {
        return match (true) {
            $bearer === null || $bearer === '' => 'none',
            str_starts_with($bearer, self::ENVIRONMENT_KEY_PREFIX) => 'environment_key',
            OrganizationKeyPrefixes::matches($bearer) => 'organization_key',
            self::claims($bearer) !== null => 'token',
            default => 'unknown',
        };
    }

    /** The environment this bearer credential acts in, or null. */
    public function of(?string $bearer): ?Environment
    {
        return match (self::kind($bearer)) {
            'environment_key' => $this->ofKey((string) $bearer),
            'token' => $this->ofIssuer(self::issuer((string) $bearer)),
            default => null,
        };
    }

    /** The environment a `cbid_env_` key was minted in; null for an unknown or revoked key. */
    public function ofKey(string $plaintext): ?Environment
    {
        $key = EnvironmentApiKey::query()
            ->withoutGlobalScope(EnvironmentScope::class)
            ->where('token_hash', hash('sha256', $plaintext))
            ->first(['id', 'environment_id', 'revoked_at', 'expires_at']);

        if ($key === null || ! $key->isActive()) {
            return null;
        }

        return self::servable(EnvironmentModel::query()->find((string) $key->environment_id));
    }

    /**
     * The same liveness gate the host resolver applies (`DatabaseEnvironmentResolver`): the
     * environment is active and the organization that owns it, through its project, is
     * active too. The resolver's `forKey()` deliberately skips it (it rehydrates past
     * events), so a key read here must not lean on it: a suspended environment's key, or a
     * suspended customer's, pins nothing and its door refuses it. A key whose environment
     * row is gone pins nothing either.
     */
    private static function servable(?EnvironmentModel $environment): ?EnvironmentModel
    {
        if ($environment === null || ! $environment->status->canServe()) {
            return null;
        }

        if ($environment->project_id === null) {
            return $environment;
        }

        // Raw queries: `organizations` is environment-owned, and nothing is current yet.
        $organizationId = DB::table('projects')->where('id', $environment->project_id)->value('organization_id');

        if (! is_string($organizationId) || $organizationId === '') {
            return null;
        }

        return DB::table('organizations')->where('id', $organizationId)->where('status', 'active')->exists() ? $environment : null;
    }

    /** The id of the key row behind a `cbid_env_` key, for counting who still calls. */
    public static function keyId(string $plaintext): ?string
    {
        $id = EnvironmentApiKey::query()
            ->withoutGlobalScope(EnvironmentScope::class)
            ->where('token_hash', hash('sha256', $plaintext))
            ->value('id');

        return is_string($id) ? $id : null;
    }

    /** The environment whose issuer is exactly $issuer. */
    public function ofIssuer(string $issuer): ?Environment
    {
        $issuer = rtrim(trim($issuer), '/');

        if ($issuer === '') {
            return null;
        }

        $host = parse_url($issuer, PHP_URL_HOST);
        $candidates = [];

        if (is_string($host) && $host !== '') {
            $candidates[] = $this->environments->resolveForHost(mb_strtolower($host));
        }

        $candidates[] = $this->root->environment();
        $candidates[] = $this->environments->defaultEnvironment();

        foreach ($candidates as $candidate) {
            if ($candidate instanceof Environment
                && hash_equals(rtrim($this->issuers->forEnvironment($candidate->environmentKey()), '/'), $issuer)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Whether $environment is the platform root. */
    public function isRoot(?Environment $environment): bool
    {
        $root = $this->root->environment()?->environmentKey();

        return $environment !== null && $root !== null && $environment->environmentKey() === $root;
    }

    /**
     * The claims of a JWT, decoded WITHOUT verifying it: for routing and counting only.
     *
     * @return array<string, mixed>|null
     */
    public static function claims(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $claims = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($claims) || ! isset($claims['iss']) || ! is_string($claims['iss'])) {
            return null;
        }

        $named = [];

        foreach ($claims as $claim => $value) {
            $named[(string) $claim] = $value;
        }

        return $named;
    }

    /** The `iss` of a JWT, unverified; empty when it has none. */
    private static function issuer(string $token): string
    {
        $issuer = self::claims($token)['iss'] ?? null;

        return is_string($issuer) ? $issuer : '';
    }
}
