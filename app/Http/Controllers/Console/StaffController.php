<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

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
        $this->assertEnvironmentAdmin();

        return $this->page('environment/staff/index', 'Staff', [
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
        ]);
    }

    public function store(GrantStaffRoleRequest $request, StaffRoles $staff): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        // Through the environment-scoped model: an address that belongs to somebody in
        // another environment is nobody here.
        $user = User::query()->where('email', $request->email())->first();

        if ($user === null) {
            return back()->withInput()->withErrors(['email' => 'Nobody in this environment uses that address.']);
        }

        // Asked of the database, not of the picker that drew the option: a posted id is
        // anything a client chooses to send.
        if (! $staff->isGrantable($request->roleId())) {
            return back()->withInput()->withErrors(['role' => 'That role cannot be granted across the environment.']);
        }

        $refusal = $staff->grant($user->id, $request->roleId());

        if ($refusal !== null) {
            return back()->withInput()->withErrors(['role' => $refusal->message()]);
        }

        return back()->with('status', 'Staff role granted to '.$user->email.'.');
    }

    public function destroy(string $user, string $role, StaffRoles $staff): RedirectResponse
    {
        $this->assertEnvironmentAdmin();

        $staff->revoke($user, $role);

        return back()->with('status', 'Staff role taken back.');
    }

    private function assertEnvironmentAdmin(): void
    {
        abort_if(app(EnvironmentAdminAuth::class)->membership() === null, 403);
    }
}
