<?php

declare(strict_types=1);

namespace App\Platform\AccountLinking;

use App\Platform\Social\OperatorProvider;
use App\Platform\Social\OperatorProviders;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\SignInProviders;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\ProviderCatalog;
use Cbox\Id\Identity\Models\IdentityLink;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Illuminate\Database\Eloquent\Model;

/**
 * THE WAYS IN THAT BELONG TO SOMEBODY ELSE — the linked sign-in identities on one account,
 * and the providers this environment offers that are not linked yet. What My account ›
 * Sign-in methods draws, and what the disconnect action decides on.
 *
 * Read from the identity rows directly rather than through `Subjects::linkedIdentities()`,
 * which answers `(provider, subject)` and nothing else. The page needs three things that
 * contract cannot say: WHICH ROW (two Google connections are both `oidc`, so a provider
 * string is not an address for one of them — disconnecting by provider took them all),
 * WHEN it was linked, and WHEN it was last used (`last_used_at`, written by the framework
 * from laravel-id 1.26; read here as absent on an older framework rather than required).
 *
 * WHO OWNS A ROW decides whether it can be removed here:
 *
 *  - a SOCIAL identity — an environment's catalogue provider (Google for everybody), an
 *    organization's own catalogue provider, or one of the deployment operator's — is the
 *    person's to connect and disconnect;
 *  - an ENTERPRISE identity — the organization's SAML or OIDC single sign-on — is how their
 *    organization knows them, and it is shown but not removable: the next sign-in through
 *    that connection would simply bring it back, and "disconnect" would be a promise the
 *    page could not keep.
 */
final readonly class SignInIdentities
{
    public function __construct(
        private Connections $connections,
        private SignInProviders $providers,
        private OperatorProviders $operators,
    ) {}

    /**
     * Every identity linked to this account, oldest first.
     *
     * @return list<SignInIdentity>
     */
    public function forSubject(string $subjectId): array
    {
        $links = IdentityLink::query()->where('user_id', $subjectId)->orderBy('created_at')->get();

        $connectionIds = $links->pluck('connection_id')->filter()->unique()->values()->all();
        /** @var array<string, Connection> $connections */
        $connections = Connection::query()->whereIn('id', $connectionIds)->get()->keyBy('id')->all();

        return array_values($links->map(fn (IdentityLink $link): SignInIdentity => $this->describe(
            $link,
            $link->connection_id === null ? null : ($connections[$link->connection_id] ?? null),
        ))->all());
    }

    /** One of this account's identities, or null — never somebody else's. */
    public function find(string $subjectId, string $identityId): ?SignInIdentity
    {
        $link = IdentityLink::query()->where('user_id', $subjectId)->whereKey($identityId)->first();

        if ($link === null) {
            return null;
        }

        return $this->describe($link, $link->connection_id === null ? null : $this->connections->byId($link->connection_id));
    }

    /**
     * Would removing this identity leave the person with no way in at all?
     *
     * Counted: a password, a passkey, any OTHER linked identity. A magic link is not
     * counted — the environment can switch it off tomorrow, and the guard has to hold
     * after that.
     */
    public function isLastWayIn(string $subjectId, string $identityId): bool
    {
        $others = IdentityLink::query()->where('user_id', $subjectId)->whereKeyNot($identityId)->exists();

        return ! $others
            && ! WebAuthnCredential::query()->where('user_id', $subjectId)->exists()
            && ! self::hasPassword($subjectId);
    }

    /** Remove exactly one identity of this account's. False when it was not there. */
    public function remove(string $subjectId, string $identityId): bool
    {
        return IdentityLink::query()->where('user_id', $subjectId)->whereKey($identityId)->delete() > 0;
    }

    /**
     * The providers somebody signing in here is offered and this account has not linked —
     * what the page draws "Connect" buttons for.
     *
     * The environment's own (and, for a member, their organization's, which replaces the
     * environment's for the same provider), then the deployment operator's where no
     * connection already offers that provider — the same precedence as the sign-in page's
     * buttons, so the list here is the list there. A provider already linked by any route
     * is not offered twice: one Google per account is what a person means by "Google".
     *
     * @param  list<SignInIdentity>  $linked
     * @return list<array{provider: string, label: string, connectHref: string}>
     */
    public function connectable(array $linked, ?string $organizationId): array
    {
        $taken = array_map(static fn (SignInIdentity $identity): string => $identity->providerKey, $linked);
        $offered = [];

        foreach ([null, $organizationId] as $scope) {
            if ($scope === '') {
                continue;
            }

            foreach ($this->providers->offeredTo($scope) as $connection) {
                $key = (string) $connection->provider;

                if ($key === '' || ! $connection->isActive()) {
                    continue;
                }

                $offered[$key] = [
                    'provider' => $key,
                    'label' => $connection->name,
                    'connectHref' => route('account.sign-in-methods.connect', $connection->id),
                ];
            }
        }

        foreach ($this->operators->all() as $operator) {
            $offered[$operator->key()] ??= [
                'provider' => $operator->key(),
                'label' => $operator->label(),
                'connectHref' => route('social.connect', $operator->key()),
            ];
        }

        return array_values(array_filter(
            $offered,
            static fn (array $provider): bool => ! in_array($provider['provider'], $taken, true),
        ));
    }

    private function describe(IdentityLink $link, ?Connection $connection): SignInIdentity
    {
        $raw = $link->raw;
        $email = is_string($raw['email'] ?? null) && $raw['email'] !== '' ? $raw['email'] : null;

        // An operator provider stamps `social:<key>`; an OAuth 2.0 connection `oauth2:<key>`.
        $operatorKey = str_starts_with($link->provider, 'social:') ? substr($link->provider, 7) : null;
        $catalogueKey = $connection->provider ?? $operatorKey;
        $template = $catalogueKey === null ? null : ProviderCatalog::find($catalogueKey);
        $operator = $operatorKey === null ? null : $this->operators->find($operatorKey);

        $social = $operatorKey !== null || ($connection !== null && $template !== null);

        $label = match (true) {
            $connection !== null => $connection->name,
            $operator instanceof OperatorProvider => $operator->label(),
            $template !== null => $template->name,
            default => ucfirst((string) ($catalogueKey ?? $link->provider)),
        };

        return new SignInIdentity(
            id: $link->id,
            providerKey: $social ? (string) $catalogueKey : 'sso',
            label: $label,
            email: $email,
            linkedAt: self::date($link->getAttribute('created_at')),
            // Absent before laravel-id 1.26 added the column — read, never required.
            lastUsedAt: self::date($link->getAttribute('last_used_at')),
            removable: $social,
        );
    }

    /** A cast date, or the raw column string an uncast attribute comes back as. */
    private static function date(mixed $value): ?CarbonInterface
    {
        return match (true) {
            $value instanceof CarbonInterface => $value,
            is_string($value) && $value !== '' => CarbonImmutable::parse($value),
            default => null,
        };
    }

    private static function hasPassword(string $subjectId): bool
    {
        $model = config('cbox-id.models.user');

        // config() is untyped; is_a(..., allow_string: true) is what turns the value into a
        // class-string<Model> the query builder can be resolved against.
        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            return false;
        }

        return $model::query()->whereKey($subjectId)->value('password') !== null;
    }
}
