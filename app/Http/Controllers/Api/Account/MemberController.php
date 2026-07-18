<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Account;

use App\Http\Controllers\Controller;
use App\Mail\AccountInviteMail;
use App\Platform\AccountApiContext;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Enums\AccountRole;
use Cbox\Id\Platform\Models\AccountMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

/**
 * Account plane › members. Lists the account's team and invites new members (who
 * receive a signed accept link, exactly as the console invite does).
 */
final class MemberController extends Controller
{
    public function index(Request $request, AccountApiContext $context, AccountMembers $members): JsonResponse
    {
        $limit = min(100, max(1, $request->integer('limit', 50)));
        $roster = $members->forAccount((string) $context->accountId());
        $page = $roster->take($limit);

        return response()->json([
            'data' => $page->map(fn (AccountMember $m): array => $this->present($m))->values()->all(),
            'meta' => ['limit' => $limit, 'has_more' => $roster->count() > $limit],
        ]);
    }

    public function store(Request $request, AccountApiContext $context, AccountMembers $members): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'role' => ['required', Rule::in(array_map(fn (AccountRole $r) => $r->value, AccountRole::assignable()))],
        ]);

        $key = $context->key();
        $account = $key?->account;

        if ($key === null || $account === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Account not found.'], 404);
        }

        $email = $request->string('email')->toString();

        if ($members->findByEmail($email) !== null) {
            return response()->json(['error' => 'email_taken', 'message' => 'That email already belongs to a member.'], 422);
        }

        $role = $request->enum('role', AccountRole::class) ?? AccountRole::Viewer;
        $name = $request->filled('name') ? $request->string('name')->toString() : null;

        $invited = $members->invite($account->id, $email, $role, $name);

        $url = URL::temporarySignedRoute('workspace.invite.accept', now()->addDays(7), ['member' => $invited->id]);
        Mail::to($invited->email)->send(new AccountInviteMail(
            account: $account->name,
            inviter: $key->name,
            url: $url,
        ));

        return response()->json(['data' => $this->present($invited)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AccountMember $member): array
    {
        return [
            'id' => $member->id,
            'email' => $member->email,
            'name' => $member->name,
            'role' => $member->role->value,
            'status' => $member->status,
            'all_environments' => $member->all_environments,
        ];
    }
}
