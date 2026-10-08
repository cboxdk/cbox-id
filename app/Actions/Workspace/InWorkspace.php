<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use App\Platform\Actions\Principal\Principal;
use App\Platform\Actions\Principal\RootPersonPrincipal;
use App\Platform\Actions\Principal\WorkspaceKeyPrincipal;
use App\Platform\Invitations\ValueObjects\Inviter;
use App\Platform\OrganizationActivity;
use App\Platform\OrganizationCapabilities;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\KeyProvenance;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

/**
 * What every workspace action needs to know about who is acting, whichever door they came
 * through: a person in the workspace console, a person through a token the platform root
 * issued them ({@see RootPersonPrincipal} — the same member, without the browser), or a
 * workspace key. A helper, not an action.
 *
 * ONE ANSWER PER QUESTION, AND THE CONSOLE'S. The workspace console recorded its acts as
 * an organization member's, named the inviter by the person's name, and fenced every id
 * by the organization in the query; a key is recorded as a service, named by the key's
 * name, and fenced the same way. Each answer is written once here so an action cannot
 * give one door a different trail from the other.
 */
final class InWorkspace
{
    /**
     * The workspace (an organization in the platform root that owns projects) the
     * principal acts for.
     *
     * @throws AuthorizationException
     */
    public static function id(Principal $principal): string
    {
        return match (true) {
            $principal instanceof WorkspaceKeyPrincipal => $principal->key()->organization_id,
            $principal instanceof ConsoleSessionPrincipal => $principal->scope()->requireOrganizationId(),
            $principal instanceof RootPersonPrincipal => $principal->workspace()->id ?? throw new AuthorizationException('You are not on a workspace\'s team.'),
            default => throw new AuthorizationException('Only a workspace member or a workspace key acts on a workspace.'),
        };
    }

    /** What the principal may do in the workspace — null for nobody on its team. */
    public static function capabilities(Principal $principal): ?OrganizationCapabilities
    {
        return match (true) {
            $principal instanceof WorkspaceKeyPrincipal => $principal->capabilities(),
            $principal instanceof ConsoleSessionPrincipal => $principal->scope()->capabilities(),
            $principal instanceof RootPersonPrincipal => $principal->workspace()?->capabilities(),
            default => null,
        };
    }

    /**
     * Who the trail names. A person — in the workspace console, or through a token they
     * signed in — is the workspace's MEMBER: not a user of whichever environment serves the
     * console, which is what the console scope's own actor would say, and never the
     * person-and-client id a token's principal keys its idempotency on. A key is a service.
     */
    public static function actor(Principal $principal): AuditActor
    {
        return $principal instanceof WorkspaceKeyPrincipal
            ? AuditActor::service($principal->id())
            : AuditActor::organizationMember(self::personId($principal) ?? $principal->id());
    }

    /** How a key this principal mints records its maker ({@see KeyProvenance}). */
    public static function creatorType(Principal $principal): string
    {
        return $principal instanceof WorkspaceKeyPrincipal ? 'workspace_key' : 'organization_member';
    }

    /**
     * Who a key this principal mints records as its maker: the key, or the PERSON — whose
     * approvals the minted key's held actions then go to ({@see KeyProvenance}).
     */
    public static function creatorId(Principal $principal): ?string
    {
        $id = $principal instanceof WorkspaceKeyPrincipal ? $principal->id() : self::personId($principal);

        return $id === null || $id === '' ? null : $id;
    }

    /** The person acting — in the console, or through a token — or null for a key, which is nobody's. */
    public static function personId(Principal $principal): ?string
    {
        return match (true) {
            $principal instanceof ConsoleSessionPrincipal => $principal->id() !== '' ? $principal->id() : null,
            $principal instanceof RootPersonPrincipal => $principal->subjectId(),
            default => null,
        };
    }

    /**
     * Record an act on the workspace's own activity log, as the principal.
     *
     * The REQUEST is the one being served, for the address the trail keeps: the console
     * passed it by hand, and an action does not know whether there is one — from a queue
     * there is not, and the entry simply has no address.
     *
     * @param  array<string, mixed>  $context
     */
    public static function record(Principal $principal, string $workspaceId, string $action, ?string $targetType = null, ?string $targetId = null, array $context = []): void
    {
        $actor = self::actor($principal);
        $request = app()->bound('request') ? app('request') : null;

        app(OrganizationActivity::class)->record(
            $workspaceId,
            $action,
            $actor->id,
            targetType: $targetType,
            targetId: $targetId,
            context: $context,
            request: $request instanceof Request ? $request : null,
            actorType: $actor->type === ActorType::Service ? ActorType::Service : ActorType::OrganizationMember,
        );
    }

    /**
     * Who an invitation names as its sender: the person by NAME (an id in a mail reads as
     * "01J9… invited you"), or the key by its own name.
     */
    public static function inviter(Principal $principal): Inviter
    {
        if ($principal instanceof WorkspaceKeyPrincipal) {
            return new Inviter($principal->key()->id, $principal->key()->name);
        }

        $actorId = self::personId($principal) ?? $principal->id();
        $subject = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->find($actorId));

        return new Inviter($actorId, $subject === null ? 'A teammate' : ($subject->name ?? $subject->email ?? 'A teammate'));
    }

    /**
     * A project of THIS workspace, or 404 — the organization in the query, never compared
     * afterwards.
     *
     * @throws ActionRefused
     */
    public static function project(string $workspaceId, string $projectId): Project
    {
        return Project::query()->whereKey($projectId)->where('organization_id', $workspaceId)->first()
            ?? throw ActionRefused::notFound('project');
    }

    /**
     * An environment of THIS workspace the principal may reach, or 404.
     *
     * A key reaches every environment its workspace owns. A person reaches those their
     * membership grants — the same set the console lists — so an id they were never shown
     * is not found rather than refused.
     *
     * @throws ActionRefused
     */
    public static function environment(Principal $principal, string $workspaceId, string $environmentId): Environment
    {
        $environment = Environment::query()
            ->whereKey($environmentId)
            ->whereIn('project_id', Project::query()->where('organization_id', $workspaceId)->pluck('id'))
            ->first();

        if ($environment === null) {
            throw ActionRefused::notFound('environment');
        }

        $personId = self::personId($principal);

        if (! $principal instanceof WorkspaceKeyPrincipal) {
            $reachable = $personId === null ? [] : (app(PlatformRoot::class)->run(
                fn (): array => app(Memberships::class)->accessibleEnvironmentIds($workspaceId, $personId),
            ) ?? []);

            if (! in_array($environment->id, $reachable, true)) {
                throw ActionRefused::notFound('environment');
            }
        }

        return $environment;
    }

    /**
     * THE rule for changing a project or adding an environment to one, from the console:
     * the capability (the action's gate) AND access that is not confined to a subset of the
     * workspace's environments — a member scoped to staging does not get to restructure the
     * products around it. A key is the workspace's, not a scoped member's, so it has no
     * subset to be confined to.
     *
     * @throws AuthorizationException
     */
    public static function assertUnscoped(Principal $principal, string $workspaceId): void
    {
        if ($principal instanceof WorkspaceKeyPrincipal) {
            return;
        }

        $personId = self::personId($principal);

        $full = $personId !== null && app(PlatformRoot::class)->run(
            fn () => app(Memberships::class)->of($workspaceId, $personId),
        )?->all_environments === true;

        if (! $full) {
            throw new AuthorizationException('Your access is limited to some of this workspace\'s environments.');
        }
    }

    /**
     * A membership of THIS workspace, or 404.
     *
     * THROUGH THE CONTRACT, in the platform root: `memberships` is tenant- and
     * environment-owned, and a bare query outside the organization's tenant scope matches
     * nothing — every write would 404 on a row that is plainly there.
     *
     * @throws ActionRefused
     */
    public static function member(string $workspaceId, string $memberId): Membership
    {
        return app(PlatformRoot::class)->run(
            fn (): ?Membership => app(Memberships::class)->forOrganization($workspaceId)->firstWhere('id', $memberId),
        ) ?? throw ActionRefused::notFound('member');
    }
}
