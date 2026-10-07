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
 * This environment's custom domain: the one serving it, and one waiting for its DNS proof.
 *
 * Only ever THIS environment — the one whose host the key was presented on. The workspace
 * console manages any of a workspace's environments by id; that is a workspace-plane act,
 * and the management API has no door onto another environment.
 */
#[AsAction(
    name: 'domains.get',
    summary: 'Read this environment\'s custom domain, and the DNS TXT record that proves a pending one.',
    scope: 'domains:read',
    danger: Danger::Read,
    schema: 'CustomDomain',
    tag: 'Domains',
    rest: ['GET', '/domains'],
)]
final readonly class ShowDomain implements Action
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

        return ActionResult::item($environment, DomainFields::present($environment, $this->domains));
    }
}
