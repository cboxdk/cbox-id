<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Organization;

use App\Http\Controllers\Controller;
use App\Platform\Invitations\Contracts\TeamInvitations;
use App\Platform\Invitations\Enums\InvitationRefusalReason;
use App\Platform\Invitations\Exceptions\InvitationRefused;
use App\Platform\Invitations\ValueObjects\Inviter;
use App\Platform\Invitations\ValueObjects\PendingInvitationSummary;
use App\Platform\OrganizationApiContext;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\ValueObjects\Subject;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Organization plane › members. Lists the workspace's team, and invites onto it — send,
 * list pending, re-send and withdraw — through {@see TeamInvitations}, the service behind the
 * console's Team page, so an invitation sent from here is the one sent from there.
 *
 * A MEMBER IS TWO ROWS HERE: the membership carries the authority (role, environment
 * grants) and the subject carries the person (name, address). This endpoint presents them
 * as one object because that is what an API consumer means by "member" — but it hydrates
 * both, and the roster does it in one pass rather than per row.
 */
final class MemberController extends Controller
{
    public function index(
        Request $request,
        OrganizationApiContext $context,
        Memberships $members,
        Subjects $subjects,
        PlatformRoot $platformRoot,
    ): JsonResponse {
        $limit = min(100, max(1, $request->integer('limit', 50)));
        $organizationId = (string) $context->organizationId();

        // IN THE PLATFORM ROOT — memberships and subjects are both environment-owned, and
        // this plane pins no environment.
        /** @var array{0: int, 1: int, 2: bool, 3: list<array<string, mixed>>} $result */
        $result = $platformRoot->run(function () use ($members, $subjects, $organizationId, $limit): array {
            // PAGINATED IN SQL, not sliced in PHP. `forOrganization()` returns the whole
            // roster: a tenant with ten thousand members loaded ten thousand rows to show
            // fifty, and `has_more` was computed from a count the caller had already paid
            // to materialise.
            // The paginator reads `page` from the request itself, which is why this takes
            // no page argument — Laravel's LengthAwarePaginator resolves it.
            $paginator = $members->paginateForOrganization($organizationId, $limit);

            /** @var list<Membership> $rows */
            $rows = array_values($paginator->items());

            // ONE QUERY FOR THE PEOPLE, not one per row. `find()` inside the loop was an
            // N+1 whose width is the page size — findMany() is the contract's documented
            // counterpart and exists for exactly this.
            $byId = $subjects->findMany(array_map(
                static fn ($membership): string => $membership->user_id,
                $rows,
            ));

            return [
                $paginator->total(),
                $paginator->currentPage(),
                $paginator->hasMorePages(),
                array_map(fn (Membership $membership): array => $this->present($membership, $byId[$membership->user_id] ?? null), $rows),
            ];
        }) ?? [0, 1, false, []];

        [$total, $page, $hasMore, $rows] = $result;

        // `has_more` WITH A WAY TO ACT ON IT. It was returned with no cursor and no page
        // parameter, so a caller told there were more members had no means of asking for
        // them — the field was true and useless.
        return response()->json([
            'data' => $rows,
            'meta' => array_filter([
                'limit' => $limit,
                'page' => $page,
                'total' => $total,
                'has_more' => $hasMore,
                'next_page' => $hasMore ? $page + 1 : null,
            ], static fn ($value): bool => $value !== null),
        ]);
    }

    /**
     * Invite somebody onto the workspace's team — through {@see TeamInvitations}, the same
     * service the console's Team page uses, so the mail, the refusals, the activity log and
     * the accept link (set a password, signed in to the console) are the same from both.
     */
    public function store(Request $request, OrganizationApiContext $context, TeamInvitations $team): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'role' => ['required', Rule::in(array_map(fn (MembershipRole $r) => $r->value, MembershipRole::assignable()))],
        ]);

        $key = $context->key();
        $organizationId = $context->organizationId();

        if ($key === null || $organizationId === null) {
            return $this->notFound();
        }

        $email = trim($request->string('email')->toString());
        $role = $request->enum('role', MembershipRole::class) ?? MembershipRole::Viewer;

        try {
            $invitation = $team->send($organizationId, $email, $role, new Inviter($key->id, $key->name), AuditActor::service($key->id));
        } catch (InvitationRefused $refused) {
            return $this->refused($refused);
        }

        return response()->json(['data' => [
            'id' => $invitation->id,
            'email' => $email,
            'role' => $role->value,
            'status' => 'invited',
        ]], 201);
    }

    /**
     * The team's pending invitations — what the console lists under Team.
     */
    public function invitations(OrganizationApiContext $context, TeamInvitations $team): JsonResponse
    {
        $organizationId = $context->organizationId();

        if ($organizationId === null) {
            return $this->notFound();
        }

        return response()->json(['data' => array_map(
            static fn (PendingInvitationSummary $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'invited_by' => $invitation->inviterName,
                'invited_at' => $invitation->invitedAt?->toIso8601String(),
                'expires_at' => $invitation->expiresAt->toIso8601String(),
            ],
            $team->pending($organizationId, 100),
        )]);
    }

    /**
     * Send a pending invitation again with a fresh link; the earlier link stops working.
     */
    public function resendInvitation(string $id, OrganizationApiContext $context, TeamInvitations $team): JsonResponse
    {
        $key = $context->key();
        $organizationId = $context->organizationId();

        if ($key === null || $organizationId === null) {
            return $this->notFound();
        }

        try {
            $sent = $team->resend($organizationId, $id, new Inviter($key->id, $key->name), AuditActor::service($key->id));
        } catch (InvitationRefused $refused) {
            return $this->refused($refused);
        }

        return response()->json(['data' => [
            'id' => $sent->id,
            'email' => $sent->email,
            'role' => $sent->role->value,
            'status' => 'invited',
        ]]);
    }

    /**
     * Withdraw a pending invitation. Its link stops working.
     */
    public function revokeInvitation(string $id, OrganizationApiContext $context, TeamInvitations $team): JsonResponse|Response
    {
        $key = $context->key();
        $organizationId = $context->organizationId();

        if ($key === null || $organizationId === null) {
            return $this->notFound();
        }

        try {
            $team->revoke($organizationId, $id, AuditActor::service($key->id));
        } catch (InvitationRefused $refused) {
            return $this->refused($refused);
        }

        return response()->noContent();
    }

    /**
     * The refusal, as this plane has always answered it: a member already on the team is
     * `email_taken` (422), an invitation that is not pending here is `not_found`.
     */
    private function refused(InvitationRefused $refused): JsonResponse
    {
        [$error, $status] = match ($refused->reason) {
            InvitationRefusalReason::AlreadyMember => ['email_taken', 422],
            InvitationRefusalReason::NotPending => ['not_found', 404],
            InvitationRefusalReason::TooSoon => ['too_soon', 429],
            InvitationRefusalReason::MailFailed => ['mail_failed', 503],
            default => ['validation_failed', 422],
        };

        return response()->json(['error' => $error, 'message' => $refused->getMessage()], $status);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['error' => 'not_found', 'message' => 'Organization not found.'], 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Membership $member, ?Subject $person): array
    {
        return [
            'id' => $member->id,
            // FROM THE SUBJECT, not the membership. A membership carries authority, not
            // identity; reading a name or an address off it is what the two-row split makes
            // impossible, which is the point of the split.
            'email' => $person?->email,
            'name' => $person?->name,
            'role' => $member->role->value,
            'status' => $member->status,
            'all_environments' => $member->all_environments === true,
        ];
    }
}
