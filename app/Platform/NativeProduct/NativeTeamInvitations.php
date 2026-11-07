<?php

declare(strict_types=1);

namespace App\Platform\NativeProduct;

use App\Models\InvitationContext;
use App\Models\InvitationRoleGrant;
use App\Platform\Actions\ActionRefused;
use App\Platform\Invitations\Contracts\OrganizationInvitations;
use App\Platform\Invitations\Exceptions\InvitationRefused;
use App\Platform\Invitations\ValueObjects\Inviter;
use App\Platform\Invitations\ValueObjects\NewInvitation;
use App\Platform\Invitations\ValueObjects\PendingInvitationSummary;
use Carbon\CarbonInterface;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\Kernel\Tenancy\GenericTenant;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\InvitationStatus;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Invitation;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Database\Eloquent\Builder;

final readonly class NativeTeamInvitations
{
    public function __construct(private NativeProductClient $client, private PlatformRoot $root, private OrganizationInvitations $invitations, private Subjects $subjects, private Memberships $members) {}

    /** @param list<string> $keys */
    public function send(string $organizationId, string $email, array $keys, Inviter $inviter, AuditActor $actor): Invitation
    {
        return $this->root->run(function () use ($organizationId, $email, $keys, $inviter, $actor): Invitation {
            $existing = $this->subjects->findByEmail($email);
            if ($existing !== null && $this->members->of($organizationId, $existing->id)?->role === MembershipRole::Owner) {
                throw InvitationRefused::alreadyMember();
            }
            $clientId = $this->client->clientId();
            $roles = Role::query()->where('client_id', $clientId)->whereNull('orphaned_at')->where('tenant_assignable', true)->whereIn('key', $keys)->get();
            if ($keys === [] || count(array_unique($keys)) !== $roles->count()) {
                throw new ActionRefused('role_not_assignable', 'Choose valid ID product roles.', 422, 'roles');
            }

            $sent = $this->invitations->send(new NewInvitation($organizationId, $email, MembershipRole::Member, $inviter, array_values(array_filter($roles->pluck('id')->all(), 'is_string')), $clientId))->invitation;
            $this->record('native.invitation_sent', $organizationId, $sent, $actor);

            return $sent;
        }) ?? throw new ActionRefused('native_product_unavailable', 'The ID platform root is unavailable.', 503);
    }

    public function resend(string $organizationId, string $id, Inviter $inviter, AuditActor $actor): Invitation
    {
        return $this->root->run(function () use ($organizationId, $id, $inviter, $actor): Invitation {
            $this->assertPending($organizationId, $id);

            $sent = $this->invitations->resend($organizationId, $id, $inviter)->invitation;
            $this->record('native.invitation_resent', $organizationId, $sent, $actor);

            return $sent;
        }) ?? throw new ActionRefused('native_product_unavailable', 'The ID platform root is unavailable.', 503);
    }

    public function revoke(string $organizationId, string $id, AuditActor $actor): void
    {
        $this->root->run(function () use ($organizationId, $id, $actor): void {
            $this->assertPending($organizationId, $id);
            $this->invitations->revoke($organizationId, $id, $actor->id);
        });
    }

    private function record(string $action, string $organizationId, Invitation $invitation, AuditActor $actor): void
    {
        app(AuditLog::class)->record(new AuditEvent(
            action: $action, actorType: $actor->type, actorId: $actor->id,
            organizationId: $organizationId, targetType: 'invitation', targetId: $invitation->id,
            context: ['client_id' => $this->client->clientId(), 'email' => $invitation->email],
        ));
    }

    /** @return list<string> */
    public function roles(Invitation $invitation): array
    {
        return $this->root->run(fn (): array => array_values(array_filter(Role::query()
            ->where('client_id', $this->client->clientId())
            ->whereIn('id', InvitationRoleGrant::query()->where('invitation_id', $invitation->id)->where('organization_id', $invitation->organization_id)->select('role_id'))
            ->orderBy('key')->pluck('key')->all(), 'is_string'))) ?? [];
    }

    /** @return list<PendingInvitationSummary> */
    public function pending(string $organizationId, int $limit = 25): array
    {
        return array_values($this->query($organizationId, fn ($query): array => $query->orderByDesc('created_at')->limit($limit)->get()->map(
            fn (Invitation $row): PendingInvitationSummary => new PendingInvitationSummary($row->id, $row->email, $row->role, $row->expires_at, ($row->getAttribute('created_at') instanceof CarbonInterface ? $row->getAttribute('created_at') : null), inviterName: InvitationContext::query()->where('invitation_id', $row->id)->first()?->invited_by_name, appName: 'Cbox ID', appRoleLabel: Role::query()->whereIn('id', InvitationRoleGrant::query()->where('invitation_id', $row->id)->select('role_id'))->orderBy('name')->pluck('name')->implode(', '), appRoleKeys: $this->roles($row)),
        )->values()->all()));
    }

    public function countPending(string $organizationId): int
    {
        return $this->query($organizationId, fn ($query): int => $query->count());
    }

    private function assertPending(string $organizationId, string $id): void
    {
        if (! $this->query($organizationId, fn ($query): bool => $query->whereKey($id)->exists())) {
            throw ActionRefused::notFound('invitation');
        }
    }

    /**
     * @template T
     *
     * @param  \Closure(Builder<Invitation>): T  $callback
     * @return T
     */
    private function query(string $organizationId, \Closure $callback): mixed
    {
        return $this->root->run(fn () => app(TenantContext::class)->runAs(GenericTenant::of($organizationId), fn () => $callback(
            Invitation::query()->where('organization_id', $organizationId)->where('status', InvitationStatus::Pending)->where('app_client_id', $this->client->clientId())->whereNull('accepted_at')->where('expires_at', '>', now()),
        ))) ?? throw new ActionRefused('native_product_unavailable', 'The ID platform root is unavailable.', 503);
    }
}
