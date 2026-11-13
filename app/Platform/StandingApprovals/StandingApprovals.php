<?php

declare(strict_types=1);

namespace App\Platform\StandingApprovals;

use App\Models\StandingApproval;
use App\Platform\Actions\ActionRefused;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\OAuthServer\Enums\GrantType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * STANDING APPROVALS — Cbox Infra's ADR-0017 Addendum A (option A, Sylvester 2026-11-13).
 *
 * A deployment a machine client proposes from CI is an agent's change, and an agent's change
 * waits for a person to approve it: the app (Cbox Infra) runs it only with a token Cbox ID
 * bound to the plan. A standing approval is the organization OWNER's standing "yes" to one
 * machine client's deployments to one of the app's environments, kept here so Cbox ID stays
 * the one that decides: while it is active, the token exchange issues the app a plan-bound
 * token for that client's deployment there ({@see StandingApprovalTokenExchange}), the same
 * shape a person's approval has, marked as coming from this grant.
 *
 * The rules, each enforced here AND by the app's engine, so neither trusts the other alone:
 *
 *  - only the organization's owner grants one; it is off until they do;
 *  - never for a production-class environment (kind `production`); `staging`, `development`
 *    and other kinds may carry one;
 *  - the client is a machine client (client credentials) of the same organization;
 *  - every grant, every use and every revocation is on the audit log
 *    (`standing_approval.granted`, `.used`, `.revoked`).
 */
final readonly class StandingApprovals
{
    /** The RFC 9396 type the app binds an approval with, and the one this covers. */
    public const string DETAILS_TYPE = 'cbox_infra_change';

    /** The entry's `approval` value for a standing approval. */
    public const string METHOD = 'standing_grant';

    /** The kinds that never carry a standing approval. */
    private const array PRODUCTION_CLASS = ['production'];

    /** An environment kind: a lower-case word. */
    private const string KIND_PATTERN = '/^[a-z][a-z0-9-]{0,31}$/D';

    public function __construct(
        private Memberships $memberships,
        private AuditLog $audit,
    ) {}

    public static function productionClass(string $kind): bool
    {
        return in_array(mb_strtolower(trim($kind)), self::PRODUCTION_CLASS, true);
    }

    /**
     * Grant (or find the already active) standing approval. `$grantedBy` must be the
     * organization's active owner.
     *
     * @throws ActionRefused
     */
    public function grant(Organization $organization, string $clientId, string $appClientId, string $environmentRef, string $environmentKind, string $grantedBy, AuditActor $actor): StandingApproval
    {
        $kind = mb_strtolower(trim($environmentKind));

        if (preg_match(self::KIND_PATTERN, $kind) !== 1) {
            throw new ActionRefused('invalid_environment_kind', 'The environment kind is a lower-case word such as staging or development.', 422, 'environment_kind');
        }

        if (self::productionClass($kind)) {
            throw new ActionRefused('production_environment', 'A production environment never carries a standing approval: its deployments always wait for a person.', 422, 'environment_kind');
        }

        if ($organization->status !== OrganizationStatus::Active) {
            throw new ActionRefused('organization_inactive', 'The organization is not active.', 409);
        }

        if (! $this->isOwner($organization->id, $grantedBy)) {
            throw new ActionRefused('not_owner', 'Only the organization\'s owner can turn on a standing approval.', 403, 'granted_by');
        }

        if (! $this->machineClientOf($organization->id, $clientId)) {
            throw new ActionRefused('unknown_client', 'That is not a machine client (client credentials) of this organization.', 422, 'client_id');
        }

        if (! $this->exchangingApp($appClientId)) {
            throw new ActionRefused('unknown_app', 'That app cannot exchange tokens for plan-bound approvals.', 422, 'app_client_id');
        }

        return DB::transaction(function () use ($organization, $clientId, $appClientId, $environmentRef, $kind, $grantedBy, $actor): StandingApproval {
            $existing = StandingApproval::query()->active()
                ->where('organization_id', $organization->id)
                ->where('client_id', $clientId)
                ->where('app_client_id', $appClientId)
                ->where('environment_ref', $environmentRef)
                ->lockForUpdate()
                ->first();

            // Already on, for the same kind: nothing changes and nothing is recorded again.
            if ($existing instanceof StandingApproval && $existing->environment_kind === $kind) {
                return $existing;
            }

            // On for another kind: the environment changed what it is, so the old grant ends.
            if ($existing instanceof StandingApproval) {
                $this->revoke($existing, $actor, 'environment_kind_changed');
            }

            $grant = StandingApproval::query()->create([
                'organization_id' => $organization->id,
                'client_id' => $clientId,
                'app_client_id' => $appClientId,
                'environment_ref' => $environmentRef,
                'environment_kind' => $kind,
                'granted_by' => $grantedBy,
                'granted_at' => now(),
                'uses' => 0,
            ]);

            $this->audit->record(new AuditEvent(
                action: 'standing_approval.granted',
                actorType: $actor->type,
                actorId: $actor->id,
                organizationId: $organization->id,
                targetType: 'standing_approval',
                targetId: $grant->id,
                context: [
                    'client_id' => $clientId,
                    'app_client_id' => $appClientId,
                    'environment_ref' => $environmentRef,
                    'environment_kind' => $kind,
                    'granted_by' => $grantedBy,
                ],
            ));

            return $grant;
        });
    }

    /** End a standing approval. Ending one already ended changes nothing and records nothing. */
    public function revoke(StandingApproval $grant, AuditActor $actor, ?string $reason = null): StandingApproval
    {
        if (! $grant->isActive()) {
            return $grant;
        }

        $grant->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->id, 'revoke_reason' => $reason])->save();

        $this->audit->record(new AuditEvent(
            action: 'standing_approval.revoked',
            actorType: $actor->type,
            actorId: $actor->id,
            organizationId: $grant->organization_id,
            targetType: 'standing_approval',
            targetId: $grant->id,
            context: array_filter([
                'client_id' => $grant->client_id,
                'app_client_id' => $grant->app_client_id,
                'environment_ref' => $grant->environment_ref,
                'environment_kind' => $grant->environment_kind,
                'reason' => $reason,
            ], static fn (?string $value): bool => $value !== null),
        ));

        return $grant;
    }

    /**
     * The active standing approval covering `$clientId`'s deployment to `$environmentRef`,
     * exchanged for by `$appClientId`, or null. Asked at every exchange: a revoked grant, a
     * client that left the organization or an organization no longer active covers nothing.
     */
    public function covering(string $organizationId, string $clientId, string $appClientId, string $environmentRef): ?StandingApproval
    {
        $organization = Organization::query()->find($organizationId);

        if (! $organization instanceof Organization || $organization->status !== OrganizationStatus::Active
            || ! $this->machineClientOf($organizationId, $clientId)) {
            return null;
        }

        return StandingApproval::query()->active()
            ->where('organization_id', $organizationId)
            ->where('client_id', $clientId)
            ->where('app_client_id', $appClientId)
            ->where('environment_ref', $environmentRef)
            ->first();
    }

    /** A token was issued under the grant: counted, stamped and recorded as the client's act. */
    public function used(StandingApproval $grant, string $planDigest, string $tokenId): void
    {
        $grant->forceFill(['last_used_at' => now(), 'uses' => $grant->uses + 1])->save();

        $this->audit->record(new AuditEvent(
            action: 'standing_approval.used',
            actorType: ActorType::Service,
            actorId: $grant->client_id,
            organizationId: $grant->organization_id,
            targetType: 'standing_approval',
            targetId: $grant->id,
            context: [
                'client_id' => $grant->client_id,
                'app_client_id' => $grant->app_client_id,
                'environment_ref' => $grant->environment_ref,
                'environment_kind' => $grant->environment_kind,
                'plan_digest' => $planDigest,
                'token_id' => $tokenId,
            ],
        ));
    }

    public function isOwner(string $organizationId, string $subjectId): bool
    {
        return $this->memberships->activeRole($organizationId, $subjectId) === MembershipRole::Owner;
    }

    /** A client of this environment that signs in as itself (client credentials) for the organization. */
    private function machineClientOf(string $organizationId, string $clientId): bool
    {
        $client = Client::query()->where('client_id', $clientId)->first();

        return $client instanceof Client
            && $client->organization_id === $organizationId
            && in_array(GrantType::ClientCredentials->value, $client->grant_types, true);
    }

    /** The app may exchange tokens and bind them to a change plan. */
    private function exchangingApp(string $appClientId): bool
    {
        $client = Client::query()->where('client_id', $appClientId)->first();

        return $client instanceof Client
            && in_array(GrantType::TokenExchange->value, $client->grant_types, true)
            && $client->allowsAuthorizationDetailsType(self::DETAILS_TYPE);
    }
}
