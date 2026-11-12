<?php

declare(strict_types=1);

namespace App\Actions\OrgPlane\Environments;

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
use App\Platform\Environments\EnvironmentErasure;
use App\Platform\Environments\EnvironmentNotErasable;

/**
 * Delete one of the organization's environments, with everything in it.
 *
 * THE MOST DESTRUCTIVE THING AN ORGANIZATION CAN DO HERE: the environment's people, apps,
 * keys, sessions and settings are removed, and every app that signs in through it stops
 * working at that moment. There is no suspended step first and no grace period, so the
 * confirmation carries the weight: the caller names the environment (`name`, compared on
 * the server whichever door asked), the console asks for a fresh sign-in on the route, and
 * a person's token waits for their approval like every {@see Danger::Critical} action.
 *
 * NEVER THE PLATFORM'S OWN. The default environment, one whose slug is a platform host,
 * and one the deployment's configuration names are refused ({@see EnvironmentErasure::protection()}).
 * They are not an organization's to reach in the first place; the rule is stated here so
 * that it does not depend on that.
 *
 * WHAT REMAINS is the platform's audit trail of the environment, kept in place, and the
 * organization's log, where this is recorded with what went. The project the environment
 * was in stays, and can be deleted once it is empty.
 */
#[AsAction(
    name: 'environments.delete',
    summary: 'Delete one of the organization\'s environments with everything in it: its people, apps, keys, sessions and settings. Apps that sign in through it stop working at once. This cannot be undone.',
    scope: 'id:environments:write',
    danger: Danger::Critical,
    plane: ActionPlane::Organization,
    rest: ['DELETE', '/environments/{environment_id}'],
    status: 204,
    consoleRoutes: ['orgs.projects.environments.destroy'],
    consoleGate: ConsoleGate::ManageEnvironments,
    tag: 'Environments',
)]
final readonly class DeleteEnvironment implements Action
{
    public function __construct(private EnvironmentErasure $erasure) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('environment_id')->inPath(),
            Field::string('name')->required()->max(255)->describe('The environment\'s name, exactly as it is, to confirm which one is deleted.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspaceId = InOrganization::id($context->principal);
        $environment = InOrganization::environment($context->principal, $workspaceId, $context->string('environment_id'));

        InOrganization::assertUnscoped($context->principal, $workspaceId);

        $protection = $this->erasure->protection($environment);

        if ($protection !== null) {
            throw self::protected($protection, $this->erasure->configuredAs($environment));
        }

        if (! hash_equals($environment->name, $context->string('name'))) {
            throw ActionRefused::because('name_mismatch', new ActionMessage('console.environment_delete_name_mismatch'), 'name');
        }

        $contents = $this->erasure->contents($environment->id);

        try {
            $this->erasure->erase($environment);
        } catch (EnvironmentNotErasable $refused) {
            if ($refused->protection !== null) {
                throw self::protected($refused->protection, $this->erasure->configuredAs($environment));
            }

            // A table this version has no decision for. Nothing was removed; somebody
            // has to decide, and that is not the person who clicked.
            report($refused);

            throw new ActionRefused('environment_delete_unavailable', new ActionMessage('console.environment_delete_unavailable'), 409);
        }

        InOrganization::record($context->principal, $workspaceId, 'organization.environment_deleted', 'environment', $environment->id, [
            'name' => $environment->name,
            'slug' => $environment->slug,
            'type' => $environment->type->value,
            'project_id' => $environment->getAttribute('project_id'),
            'people' => $contents['people'],
            'apps' => $contents['apps'],
            'organizations' => $contents['organizations'],
        ]);

        return ActionResult::none($environment);
    }

    private static function protected(string $protection, ?string $configuredAs): ActionRefused
    {
        return ActionRefused::because('environment_protected', match ($protection) {
            'reserved' => new ActionMessage('console.environment_delete_protected_reserved'),
            'configured' => new ActionMessage('console.environment_delete_protected_configured', ['key' => $configuredAs ?? '']),
            default => new ActionMessage('console.environment_delete_protected_default'),
        });
    }
}
