<?php

declare(strict_types=1);

namespace App\Actions\Workspace\Environments;

use App\Actions\Domains\AddDomain;
use App\Actions\Domains\DomainFields;
use App\Actions\Workspace\InWorkspace;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Organization\Contracts\EnvironmentDomains;
use Cbox\Id\Organization\Exceptions\InvalidCustomDomain;

/**
 * Ask to serve one of the workspace's environments on a custom domain — the workspace
 * console's Environment domains page, for whichever environment is chosen. The answer is
 * the DNS TXT record to publish; {@see VerifyEnvironmentDomain} promotes the domain once
 * it is visible.
 *
 * The environment's own key does the same from its own host ({@see AddDomain}); this is
 * the workspace's door to it, for an environment the caller can reach — a person the
 * environments their membership grants, a key every one its workspace owns. One the
 * caller cannot reach is not found, so another account's pending domain and its TXT proof
 * are never an argument to the service at all.
 */
#[AsAction(
    name: 'environments.domain.request',
    summary: 'Start serving one of the workspace\'s environments on a custom domain: returns the DNS TXT record that proves you control it.',
    scope: 'environments:write',
    danger: Danger::Write,
    plane: ActionPlane::Workspace,
    rest: ['POST', '/environments/{environment_id}/domain'],
    consoleRoutes: ['environment-domains.store'],
    consoleGate: ConsoleGate::ManageEnvironments,
    schema: 'CustomDomain',
    tag: 'Environments',
)]
final readonly class RequestEnvironmentDomain implements Action
{
    public function __construct(private EnvironmentDomains $domains) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('environment_id')->inPath(),
            Field::string('domain')->required()->max(253)->describe('The domain, for example login.example.com.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspaceId = InWorkspace::id($context->principal);
        $environment = InWorkspace::environment($context->principal, $workspaceId, $context->string('environment_id'));

        try {
            $this->domains->request($environment->id, trim($context->string('domain')));
        } catch (InvalidCustomDomain $e) {
            throw ActionRefused::because('invalid_domain', $e->getMessage(), 'domain');
        }

        $fresh = $environment->fresh() ?? $environment;

        return ActionResult::item($fresh, DomainFields::present($fresh, $this->domains));
    }
}
