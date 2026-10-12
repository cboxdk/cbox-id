<?php

declare(strict_types=1);

namespace App\Platform;

use App\Actions\SignIn\UpdateSignInPolicy;
use App\Actions\Sso\DeleteSsoConnection;
use App\Actions\Sso\DisableSsoConnection;
use App\Actions\Sso\RequireSso;
use App\Platform\Actions\ActionRefused;
use Cbox\Id\Federation\Enums\ConnectionStatus;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\SignInMethods;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Platform\Enums\OperatorStatus;
use Cbox\Id\Platform\Models\PlatformOperator;
use Cbox\Id\Platform\PlatformRoot;

/**
 * NOBODY LOCKS THE OPERATORS OUT OF THE PLATFORM — the refusal every sign-in change to the
 * platform root's own environment passes through.
 *
 * The root environment is how every workspace owner and every operator signs in to Cbox ID,
 * and with its own console ({@see EnvironmentAdminAuth::rootOperator()}) its sign-in can be
 * changed by the same actions that change a tenant's. On a tenant a bad change locks out
 * that tenant's users, and the workspace's team fixes it from the root. On the root there
 * is nowhere above to fix it from: requiring SSO for everybody, or for the organization the
 * operators belong to, without a working connection — or turning off the one method an
 * operator has — leaves the deployment with no administrator who can sign in.
 *
 * So before such a change is written, every ACTIVE operator is asked "would you still have
 * a way in afterwards?" against the state the change would leave, and the change is
 * refused, naming who would be stranded and what to do instead, if anybody would not.
 *
 * WHAT COUNTS AS A WAY IN, for an operator:
 *
 *  - where SSO is REQUIRED for them (the environment's baseline, or an organization they
 *    belong to), only an ACTIVE single sign-on connection of one of their organizations —
 *    required SSO refuses every other way in;
 *  - otherwise any of: a password, a passkey (while passkeys are on), a magic link (while
 *    magic links are on), a linked social or federated identity with a provider still
 *    offered, or an active SSO connection.
 *
 * Deliberately generous where it cannot be exact — a magic link counts for anybody with
 * an address — because a guard that refuses a safe change is worked around, and the cases
 * it exists for (required SSO with nothing behind it; the last method switched off) are
 * the ones it answers precisely.
 *
 * CALLED BY THE ACTIONS THEMSELVES — {@see UpdateSignInPolicy}, {@see RequireSso},
 * {@see DisableSsoConnection}, {@see DeleteSsoConnection} — so the console, REST, MCP and
 * the CLI all meet the same refusal. A no-op anywhere but the platform root's environment.
 */
final readonly class RootSignInGuard
{
    public function __construct(
        private PlatformRoot $root,
        private EnvironmentContext $environments,
        private AuthPolicies $policies,
        private SignInMethods $methods,
        private Memberships $memberships,
        private Subjects $subjects,
    ) {}

    /** Whether the environment this request acts in is the platform root's. */
    public function guards(): bool
    {
        $current = $this->environments->current()?->environmentKey();
        $root = $this->root->environment()?->environmentKey();

        return $current !== null && $root !== null && $current === $root;
    }

    /**
     * Refuse unless every active operator keeps a way in once $change is applied.
     *
     * @param  array{
     *     environment_sso?: SsoEnforcement,
     *     organization_sso?: array<string, SsoEnforcement>,
     *     passkeys?: bool,
     *     magic_link?: bool,
     *     connections_gone?: list<string>,
     *     field?: string,
     * }  $change
     *
     * @throws ActionRefused
     */
    public function assertOperatorsKeepAWayIn(array $change): void
    {
        if (! $this->guards()) {
            return;
        }

        $stranded = [];

        foreach ($this->operatorSubjects() as $subjectId => $label) {
            if (! $this->hasWayIn($subjectId, $change)) {
                $stranded[] = $label;
            }
        }

        if ($stranded === []) {
            return;
        }

        $who = count($stranded) === 1
            ? 'the platform operator '.$stranded[0]
            : count($stranded).' platform operators ('.implode(', ', array_slice($stranded, 0, 5)).(count($stranded) > 5 ? ', …' : '').')';

        throw ActionRefused::because(
            'would_lock_out_operators',
            'This is the platform\'s own environment, where every operator signs in, and this change would leave '.$who
                .' with no way to sign in to Cbox ID. Give them a way in that survives it first — an active single sign-on connection for their organization, a password or a passkey — then try again.',
            $change['field'] ?? null,
        );
    }

    /**
     * Every active operator's subject, by id, with what to call them in the refusal.
     *
     * @return array<string, string>
     */
    private function operatorSubjects(): array
    {
        $subjects = [];

        foreach (PlatformOperator::query()->where('status', OperatorStatus::Active)->whereNotNull('subject_id')->get() as $operator) {
            $subjects[(string) $operator->subject_id] = $operator->email;
        }

        return $subjects;
    }

    /**
     * @param  array{environment_sso?: SsoEnforcement, organization_sso?: array<string, SsoEnforcement>, passkeys?: bool, magic_link?: bool, connections_gone?: list<string>}  $change
     */
    private function hasWayIn(string $subjectId, array $change): bool
    {
        return $this->root->run(function () use ($subjectId, $change): bool {
            $organizationIds = [];

            foreach ($this->memberships->forUser($subjectId) as $membership) {
                $organizationIds[] = (string) $membership->organization_id;
            }

            $gone = $change['connections_gone'] ?? [];
            $sso = $this->activeConnection($organizationIds, $gone, environmentWide: false);

            if ($this->ssoRequired($organizationIds, $change)) {
                return $sso;
            }

            if ($sso) {
                return true;
            }

            $password = User::query()->whereKey($subjectId)->whereNotNull('password')->exists();

            if ($password) {
                return true;
            }

            $passkeys = ($change['passkeys'] ?? $this->methods->passkeysEnabled()) && $this->methods->deploymentAllowsPasskeys();

            if ($passkeys && WebAuthnCredential::query()->where('user_id', $subjectId)->exists()) {
                return true;
            }

            $magicLink = ($change['magic_link'] ?? $this->methods->magicLinkEnabled()) && $this->methods->deploymentAllowsMagicLink();

            if ($magicLink) {
                return true;
            }

            return $this->subjects->linkedIdentities($subjectId) !== []
                && $this->activeConnection($organizationIds, $gone, environmentWide: true);
        }) === true;
    }

    /**
     * Whether single sign-on is required of somebody in these organizations once $change is
     * applied — by the environment's baseline, or by an organization's own override.
     *
     * @param  list<string>  $organizationIds
     * @param  array{environment_sso?: SsoEnforcement, organization_sso?: array<string, SsoEnforcement>}  $change
     */
    private function ssoRequired(array $organizationIds, array $change): bool
    {
        $environment = $change['environment_sso'] ?? $this->policies->forEnvironment()->sso;

        if ($environment === SsoEnforcement::Required) {
            return true;
        }

        $overrides = $change['organization_sso'] ?? [];

        foreach ($organizationIds as $organizationId) {
            $sso = $overrides[$organizationId] ?? $this->policies->overrideFor($organizationId)?->sso;

            if ($sso === SsoEnforcement::Required) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an active connection survives the change — one of these organizations' own,
     * and with $environmentWide also one the environment offers to everybody.
     *
     * @param  list<string>  $organizationIds
     * @param  list<string>  $gone
     */
    private function activeConnection(array $organizationIds, array $gone, bool $environmentWide): bool
    {
        return Connection::query()
            ->where('status', ConnectionStatus::Active)
            ->when($gone !== [], static fn ($query) => $query->whereNotIn('id', $gone))
            ->where(static function ($query) use ($organizationIds, $environmentWide): void {
                $query->whereIn('organization_id', $organizationIds === [] ? [''] : $organizationIds);

                if ($environmentWide) {
                    $query->orWhereNull('organization_id');
                }
            })
            ->exists();
    }
}
