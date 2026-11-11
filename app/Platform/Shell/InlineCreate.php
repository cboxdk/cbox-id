<?php

declare(strict_types=1);

namespace App\Platform\Shell;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\ActionRunner;
use App\Platform\Actions\ActionVia;
use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use App\Platform\NativeProduct\NativeProductAccess;
use App\Platform\OrganizationCapabilities;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Validation\ValidationException;

/**
 * "New project" and "New environment" from the header's menus run the same actions the
 * Projects page runs, as the signed-in person, in the organization the menu was opened in.
 *
 * The shell endpoints carry the organization as an ancestor id rather than in the path, so
 * this binds it the way `BindOrganizationFromPath` does for a page: the level's `find()` has
 * already checked the membership, and the action's own gate checks the role again.
 */
final readonly class InlineCreate
{
    public function __construct(
        private CurrentUser $user,
        private Organizations $organizations,
        private Memberships $memberships,
        private PlatformRoot $root,
    ) {}

    /** What the person may do in that organization, or null when they are not in it. */
    public function capabilities(string $organizationId): ?OrganizationCapabilities
    {
        $subject = ShellRequest::subjectId();

        if ($subject === null) {
            return null;
        }

        return $this->root->run(static fn (): OrganizationCapabilities => app(NativeProductAccess::class)->forWebSubject($subject, $organizationId));
    }

    /**
     * @param  class-string<Action>  $action
     * @param  array<string, mixed>  $input
     */
    public function run(string $organizationId, string $action, array $input): ActionResult
    {
        $subject = $this->user->subject();
        $session = $this->user->session();
        $organization = $this->organizations->find($organizationId);
        abort_if($subject === null || $session === null || $organization === null, 403);

        $role = $this->root->run(fn (): ?MembershipRole => $this->memberships->activeRole($organizationId, $this->user->id()));
        abort_if(! $role instanceof MembershipRole, 404);

        $this->user->set($subject, $session, $organization, $role);

        try {
            return app(ActionRunner::class)->run($action, new ConsoleSessionPrincipal(app(ConsoleScope::class)), $input, via: ActionVia::Console);
        } catch (ActionRefused $refused) {
            abort_if($refused->status === 404, 404);

            throw ValidationException::withMessages(['name' => $refused->forConsole()->getMessage()]);
        }
    }
}
