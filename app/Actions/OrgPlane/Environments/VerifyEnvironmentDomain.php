<?php

declare(strict_types=1);

namespace App\Actions\OrgPlane\Environments;

use App\Actions\Domains\DomainFields;
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
use App\Platform\Actions\FrameworkMessage;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Organization\Contracts\EnvironmentDomains;
use Cbox\Id\Organization\Exceptions\InvalidCustomDomain;

/**
 * Look for the pending domain's TXT record and, once it is visible, serve the environment
 * on that domain.
 *
 * A record that is not visible yet is a refusal that says to WAIT, not a fault in the
 * domain: DNS takes minutes to propagate, and the record is probably right. A verified
 * domain is recorded on the workspace's trail (`organization.custom_domain_verified`), as
 * the person or the key that verified it.
 */
#[AsAction(
    name: 'environments.domain.verify',
    summary: 'Look for the pending domain\'s DNS TXT record and, once it is visible, serve the environment on that domain.',
    scope: 'environments:write',
    danger: Danger::Write,
    plane: ActionPlane::Organization,
    rest: ['POST', '/environments/{environment_id}/domain/verify'],
    consoleRoutes: ['environment-domains.verify'],
    consoleGate: ConsoleGate::ManageEnvironments,
    schema: 'CustomDomain',
    tag: 'Environments',
)]
final readonly class VerifyEnvironmentDomain implements Action
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
        $workspaceId = InOrganization::id($context->principal);
        $environment = InOrganization::environment($context->principal, $workspaceId, $context->string('environment_id'));

        if ($this->domains->challenge($environment->id) === null) {
            throw ActionRefused::because('no_pending_domain', new ActionMessage('console.there_is_no_pending_custom_domain_to_verify_add_one_fir_be62636f'));
        }

        try {
            $result = $this->domains->verify($environment->id);
        } catch (InvalidCustomDomain $e) {
            throw ActionRefused::because('invalid_domain', FrameworkMessage::from($e), 'domain');
        }

        if (! $result->verified) {
            throw ActionRefused::because('dns_not_propagated', new ActionMessage('console.the_dns_txt_record_isn_t_visible_yet_dns_can_take_a_few_e4111709'));
        }

        InOrganization::record($context->principal, $workspaceId, 'organization.custom_domain_verified', 'environment', $environment->id, ['domain' => $result->domain]);

        $fresh = $environment->fresh() ?? $environment;

        return ActionResult::item($fresh, DomainFields::present($fresh, $this->domains));
    }
}
