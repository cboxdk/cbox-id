<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Organization;

use App\Http\Controllers\Controller;
use App\Mail\OrganizationInviteMail;
use App\Platform\MailLinks;
use App\Platform\OrganizationApiContext;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\ValueObjects\Subject;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Organization plane › members. Lists the organization's team and invites new members (who
 * receive a signed accept link, exactly as the console invite does).
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

    public function store(
        Request $request,
        OrganizationApiContext $context,
        Memberships $members,
        Invitations $invitations,
        Subjects $subjects,
        Organizations $organizations,
        PlatformRoot $platformRoot,
        MailLinks $links,
    ): JsonResponse {
        $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'role' => ['required', Rule::in(array_map(fn (MembershipRole $r) => $r->value, MembershipRole::assignable()))],
        ]);

        $key = $context->key();
        $organizationId = $context->organizationId();

        $organization = $organizationId === null
            ? null
            : $platformRoot->run(fn () => $organizations->find($organizationId));

        if ($key === null || $organization === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Organization not found.'], 404);
        }

        $email = $request->string('email')->toString();
        $role = $request->enum('role', MembershipRole::class) ?? MembershipRole::Viewer;

        $existing = $platformRoot->run(fn () => $subjects->findByEmail($email));

        if ($existing !== null && $platformRoot->run(fn () => $members->of($organization->id, $existing->id)) !== null) {
            return response()->json(['error' => 'email_taken', 'message' => 'That email already belongs to a member.'], 422);
        }

        $pending = $platformRoot->run(
            fn () => $invitations->invite($organization->id, $email, $role, $key->id),
        );

        if ($pending === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Organization not found.'], 404);
        }

        // MailLinks, not URL:: — the console invite and this one mint the SAME link, so
        // they mint it the same way (see that class); an API caller's Host is no more
        // trustworthy than a browser's.
        $url = $links->temporarySignedRoute('organization.invite.accept', now()->addDays(7), ['token' => $pending->token]);
        Mail::to($email)->send(new OrganizationInviteMail(
            organization: $organization->name,
            inviter: $key->name,
            url: $url,
        ));

        return response()->json(['data' => [
            'id' => $pending->invitation->id,
            'email' => $email,
            'role' => $role->value,
            'status' => 'invited',
        ]], 201);
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
