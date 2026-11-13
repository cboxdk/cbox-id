<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\Actions\Organizations\OrganizationFields;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionMessage;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\OrganizationAccess;
use App\Platform\OrgRoles;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Validation\ValidationException;

/**
 * Add an existing user to an organization, on a tier, with the access roles they should
 * hold there — the membership through {@see Memberships::add()} (which records and announces
 * it), each role through the environment plane's grant, segregation of duties asked first.
 *
 * The person is named by `user_id`, or by `email` — the console's form asks for an address.
 * Ownership is never assigned (`owner` is not a tier this takes): it moves with
 * `transfer-ownership`.
 *
 * Idempotent for the same tier: repeating the request answers 200 with the membership it
 * already made. A person who is a member on a DIFFERENT tier is a 409 — changing a tier is
 * `PATCH`, which says what it does. An organization that is suspended or archived takes no
 * new members: existence is not life, and a membership written into one would be access
 * through an organization that refuses everything.
 */
#[AsAction(
    name: 'members.add',
    summary: 'Add an existing user to an organization on a tier (`admin` or `member`), optionally with access roles. Name them by `user_id` or `email`.',
    scope: 'members:write',
    danger: Danger::Write,
    schema: 'Member',
    tag: 'Members',
    rest: ['POST', '/organizations/{organization_id}/members'],
    status: 201,
    consoleRoutes: ['environment.organizations.members.store', 'environment.users.organizations.store'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class AddMember implements Action
{
    public function __construct(private Memberships $memberships) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization id.'),
            Field::string('user_id')->max(64)->describe('The user to add. Or name them by `email`.'),
            Field::string('email')->format('email')->max(190)->describe('The address of the user to add, instead of `user_id`.'),
            Field::string('role')->oneOf(array_map(static fn (MembershipRole $role): string => $role->value, OrgRoles::assignable()))->describe('The tier. Default `member`; ownership moves with transfer-ownership.'),
            Field::list('roles', Field::string('role')->max(190))->max(50)->describe('Access roles to grant in the organization: ids, or manifest keys of `client_id`\'s app.'),
            Field::string('client_id')->nullable()->max(255)->describe('The app whose manifest keys `roles` are.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organization = OrganizationFields::find($context, $context->string('organization_id'));
        $userId = $this->userId($context);

        $refusal = OrganizationAccess::refusalPhrase($organization->status);

        if ($refusal !== null) {
            throw new ActionRefused('organization_inactive', new ActionMessage($refusal === 'suspended' ? 'console.that_organization_has_been_suspended_and_cannot_take_ne_0504f283' : 'console.that_organization_has_been_deleted_and_cannot_take_new_52858567'), 409, 'organization_id');
        }

        $role = MembershipRole::tryFrom($context->string('role')) ?? MembershipRole::Member;
        $roles = MemberFields::assignableRoles($organization->id, $context->array('roles'), $context->nullableString('client_id'));

        $existing = $this->memberships->of($organization->id, $userId);

        if ($existing !== null && $existing->role !== $role) {
            throw new ActionRefused('already_member', new ActionMessage('console.that_user_is_already_a_member_as_part1_change_their_tie_1632ba60', ['part1' => new ActionMessage(match ($existing->role) {
                MembershipRole::Owner => 'console.owner_4c102969', MembershipRole::Admin => 'console.admin_8c6976e5', MembershipRole::Developer => 'console.developer_88fa0d75', MembershipRole::Member => 'console.member_e31ab643', MembershipRole::Viewer => 'console.viewer_d35ca505',
            })]), 409, 'user_id');
        }

        $membership = $existing ?? $this->memberships->add($organization->id, $userId, $role);

        foreach ($roles as $grant) {
            MemberFields::grant($organization->id, $userId, $grant, 'roles');
        }

        return ActionResult::item($membership, MemberFields::present($membership), $existing === null ? 201 : 200);
    }

    /**
     * The person, by id or by address, in THIS environment's user store — an id or an address
     * from another environment is nobody here.
     *
     * @throws ActionRefused
     * @throws ValidationException
     */
    private function userId(ActionContext $context): string
    {
        $id = $context->nullableString('user_id');

        if ($id !== null) {
            return User::query()->whereKey($id)->exists()
                ? $id
                : throw ActionRefused::because('user_not_found', new ActionMessage('console.no_user_with_that_user_id_exists_in_this_environment_2d0b29cc'), 'user_id');
        }

        $email = $context->nullableString('email');

        if ($email === null) {
            throw ValidationException::withMessages(['user_id' => 'Name the user to add, by user_id or by email.']);
        }

        /*
         * ONE ACCOUNT, THE SAME ONE EVERY TIME. Case variants of an address can exist as
         * separate accounts (signup used to match byte for byte), and this used to take
         * whichever the database returned first, so a variant somebody registered ahead of
         * the real person could receive the membership. Only a confirmed address can be
         * the person (below); among confirmed variants the exact spelling decides, and
         * without one the administrator is asked to name the account by `user_id`.
         */
        $candidates = User::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])
            ->orderBy('id')
            ->get();

        $confirmed = $candidates->filter(static fn (User $candidate): bool => $candidate->getAttribute('email_verified_at') !== null);

        if ($confirmed->count() > 1) {
            $confirmed = $confirmed->filter(static fn (User $candidate): bool => $candidate->getAttribute('email') === trim($email));

            if ($confirmed->count() !== 1) {
                throw new ActionRefused('email_ambiguous', new ActionMessage('console.several_accounts_share_that_address_name_the_one_you_2c942f61'), 409, 'email');
            }
        }

        $user = $confirmed->first() ?? $candidates->first();

        if ($user === null) {
            throw ActionRefused::because('user_not_found', new ActionMessage('console.no_user_with_that_email_in_this_environment_create_the_c8a9a675'), 'email');
        }

        /*
         * AN ADDRESS NOBODY CONFIRMED IS NOT A PERSON. Signup makes an account for any
         * typed address, so the account found under the one an administrator typed may
         * belong to somebody else entirely — and a membership would hand them the
         * organization. An invitation proves the mailbox before anything attaches; an
         * administrator who means a particular account can still pick it by `user_id`.
         */
        if ($user->getAttribute('email_verified_at') === null) {
            throw new ActionRefused('email_unverified', new ActionMessage('console.that_address_has_not_been_confirmed_so_the_account_may_e0febd82'), 409, 'email');
        }

        return $user->id;
    }
}
