<?php

declare(strict_types=1);

namespace App\Actions\Domains;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\EnvironmentWorkspace;
use Cbox\Id\Organization\Contracts\EnvironmentDomains;

/**
 * Stop serving this environment on its custom domain, and drop any pending one.
 *
 * Destructive: anything reaching the environment by that domain — sign-in pages, the issuer
 * apps were configured with, and this API if it was called there — stops resolving to it.
 * Recorded on the workspace's trail as the workspace console has always recorded it.
 */
#[AsAction(
    name: 'domains.remove',
    summary: 'Stop serving this environment on its custom domain. Anything using that domain stops reaching the environment.',
    scope: 'domains:write',
    danger: Danger::Destructive,
    tag: 'Domains',
    rest: ['DELETE', '/domains'],
    status: 204,
)]
final readonly class RemoveDomain implements Action
{
    public function __construct(
        private EnvironmentWorkspace $workspace,
        private EnvironmentDomains $domains,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $environment = $this->workspace->environment() ?? throw ActionRefused::notFound('environment');

        $this->domains->clear($environment->id);

        $this->workspace->record($context->principal, $environment->id, 'organization.custom_domain_removed');

        return ActionResult::none($environment);
    }
}
