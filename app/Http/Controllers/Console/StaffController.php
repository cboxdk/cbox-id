<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Users\GrantStaffRole;
use App\Actions\Users\RevokeStaffRole;
use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\StaffRoleProps;
use App\Http\Requests\Console\GrantStaffRoleRequest;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\Help\HelpTopic;
use App\Platform\Staff\Contracts\StaffRoles;
use App\Platform\Staff\ValueObjects\StaffGrant;
use Cbox\Id\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * ENVIRONMENT CONSOLE › PEOPLE › STAFF — who holds a role across the whole environment.
 *
 * A staff role is granted to the environment's own people (support, operations) and
 * applies in every organization; an app's own role granted this way reaches only that
 * app. Organizations never see these grants. The page exists because the only place they
 * could be made was a checkbox on one user's page, so nobody could answer "who has
 * support access to Parcels?" without opening every user.
 *
 * Environment console only: an organization's administrators never grant a staff role,
 * and the framework refuses one on their side.
 */
final readonly class StaffController extends ConsoleController
{
    public function index(StaffRoles $staff): Response
    {
        abort_unless(app(EnvironmentAdminAuth::class)->canRead(), 403);

        return $this->page('environment/staff/index', __('console.admins_support_6053c299'), [
            'help' => HelpProps::for(HelpTopic::Staff),
            'grants' => array_map(static fn (StaffGrant $grant): array => [
                'userId' => $grant->userId,
                'userName' => $grant->userName,
                'userEmail' => $grant->userEmail,
                'role' => StaffRoleProps::from($grant->role),
                'source' => $grant->source->value,
                'userHref' => route('environment.users.show', $grant->userId),
                'revokeHref' => route('environment.staff.destroy', ['user' => $grant->userId, 'role' => $grant->role->id]),
            ], $staff->grants()),
            'roles' => StaffRoleProps::list($staff->grantable()),
            'storeHref' => route('environment.staff.store'),
            'rolesHref' => route('environment.roles'),
            'reviewHref' => route('environment.governance.create', ['review' => 'staff']),
            // A person's role inside ONE organization is not granted here; the page says where.
            'organizationsHref' => route('environment.organizations'),
        ]);
    }

    public function store(GrantStaffRoleRequest $request, StaffRoles $staff): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        // Through the environment-scoped model: an address that belongs to somebody in
        // another environment is nobody here.
        $user = User::query()->where('email', $request->email())->first();

        if ($user === null) {
            return back()->withInput()->withErrors(['email' => __('console.nobody_in_this_environment_uses_that_address_d783f5b0')]);
        }

        // Asked of the database, not of the picker that drew the option: a posted id is
        // anything a client chooses to send.
        if (! $staff->isGrantable($request->roleId())) {
            return back()->withInput()->withErrors(['role' => __('console.that_role_cannot_be_granted_across_the_environment_fc38a84c')]);
        }

        // The same action the user page and the management API grant one with, so
        // segregation of duties is asked one way and a refusal names where it collides.
        $result = $this->act(GrantStaffRole::class, ['id' => $user->id, 'role_id' => $request->roleId()], ['role_id' => 'role'], 'role');

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', __('console.admin_support_role_granted_to_part1_4d9914b8', ['part1' => $user->email]));
    }

    public function destroy(string $user, string $role): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $result = $this->act(RevokeStaffRole::class, ['id' => $user, 'role_id' => $role], ['role_id' => 'role'], 'role');

        return $result instanceof RedirectResponse ? $result : back()->with('status', __('console.admin_support_role_taken_back_b60bab30'));
    }

    private function assertEnvironmentAdmin(): void
    {
        abort_unless(app(EnvironmentAdminAuth::class)->check(), 403);
    }
}
