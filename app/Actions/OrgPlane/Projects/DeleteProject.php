<?php

declare(strict_types=1);

namespace App\Actions\OrgPlane\Projects;

use App\Actions\OrgPlane\InOrganization;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionMessage;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Billing\ProjectSubscriptions;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Models\Project;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Delete a project. Suspended or active alike, and the organization's last one too: an
 * organization with no projects is an ordinary state, and the only one it can be archived
 * from.
 *
 * ONLY AN EMPTY ONE. A project is deleted when it holds no environments; it never takes
 * them with it. An environment is somebody's people, apps and keys, and removing those is
 * its own act with its own confirmation, not a side effect of tidying the project list.
 *
 * NOT WHILE A PLAN RENEWS ON IT. The project is the billing anchor: its subscription lives
 * in Cbox Billing under the project's id, and deleting the row here would leave the
 * organization charged for a product it can no longer see. Asked BEFORE the row lock,
 * because it is a network call; a Billing that cannot be reached refuses rather than
 * guesses.
 *
 * WHAT GOES WITH IT is the row and nothing else, because nothing else is the project's:
 * `environments.project_id` is the one column in the schema that names a project, and the
 * rule above means no row holds this one. Its settings are a column on the row. Team
 * access is granted per environment, usage is metered per organization, and the
 * organization's log keeps every entry about the project, this one included.
 */
#[AsAction(
    name: 'projects.delete',
    summary: 'Delete a project that holds no environments. One that still has environments, or a plan that still renews, is refused.',
    scope: 'id:projects:write',
    danger: Danger::Destructive,
    plane: ActionPlane::Organization,
    rest: ['DELETE', '/projects/{id}'],
    status: 204,
    consoleRoutes: ['orgs.projects.destroy'],
    consoleGate: ConsoleGate::ManageEnvironments,
    tag: 'Projects',
)]
final readonly class DeleteProject implements Action
{
    public function __construct(private ProjectSubscriptions $subscriptions) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([Field::string('id')->inPath()]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspaceId = InOrganization::id($context->principal);
        $project = InOrganization::project($workspaceId, $context->string('id'));

        InOrganization::assertUnscoped($context->principal, $workspaceId);

        // Said before Billing is asked: the environments are the first thing to deal with,
        // and the answer costs no network call. Asked again under the lock below.
        self::assertEmpty($project);

        try {
            $renews = $this->subscriptions->renews($workspaceId, $project->id);
        } catch (Throwable $error) {
            report($error);

            throw new ActionRefused('billing_unavailable', new ActionMessage('console.project_delete_billing_unavailable'), 503);
        }

        if ($renews) {
            throw ActionRefused::because('project_has_subscription', new ActionMessage('console.project_delete_has_subscription'));
        }

        DB::transaction(function () use ($workspaceId, $project): void {
            // The same row lock adding an environment takes ({@see \App\Actions\OrgPlane\Environments\CreateEnvironment}),
            // so "empty" is still true when the row goes: an environment being added to
            // this project either committed before the count or waits and finds no project.
            $locked = Project::query()
                ->whereKey($project->id)
                ->where('organization_id', $workspaceId)
                ->lockForUpdate()
                ->first() ?? throw ActionRefused::notFound('project');

            self::assertEmpty($locked);

            $locked->delete();
        });

        InOrganization::record($context->principal, $workspaceId, 'organization.project_deleted', 'project', $project->id, [
            'name' => $project->name,
            'slug' => $project->slug,
            'status' => $project->status->value,
        ]);

        return ActionResult::none($project);
    }

    /** @throws ActionRefused */
    private static function assertEmpty(Project $project): void
    {
        if (Environment::query()->where('project_id', $project->id)->exists()) {
            throw ActionRefused::because('project_has_environments', new ActionMessage('console.project_delete_has_environments'));
        }
    }
}
