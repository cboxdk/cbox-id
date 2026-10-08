<?php

declare(strict_types=1);

namespace App\Actions\Workspace\Environments;

use App\Actions\Workspace\InWorkspace;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Organization\Contracts\EnvironmentDomains;

/**
 * Stop serving one of the workspace's environments on its custom domain, and drop any
 * pending one; the environment falls back to its default issuer.
 *
 * Destructive: anything reaching the environment by that domain — its sign-in pages, the
 * issuer apps were configured with — stops resolving to it. Recorded on the workspace's
 * trail (`organization.custom_domain_removed`) every time it is asked, as the console
 * always has.
 */
#[AsAction(
    name: 'environments.domain.remove',
    summary: 'Stop serving one of the workspace\'s environments on its custom domain. Anything using that domain stops reaching it.',
    scope: 'environments:write',
    danger: Danger::Destructive,
    plane: ActionPlane::Workspace,
    rest: ['DELETE', '/environments/{environment_id}/domain'],
    status: 204,
    consoleRoutes: ['environment-domains.destroy'],
    consoleGate: ConsoleGate::ManageEnvironments,
    tag: 'Environments',
)]
final readonly class RemoveEnvironmentDomain implements Action
{
    public function __construct(private EnvironmentDomains $domains) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('environment_id')->inPath(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspaceId = InWorkspace::id($context->principal);
        $environment = InWorkspace::environment($context->principal, $workspaceId, $context->string('environment_id'));

        $this->domains->clear($environment->id);

        InWorkspace::record($context->principal, $workspaceId, 'organization.custom_domain_removed', 'environment', $environment->id);

        return ActionResult::none($environment);
    }
}
