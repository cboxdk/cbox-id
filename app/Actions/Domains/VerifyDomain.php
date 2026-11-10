<?php

declare(strict_types=1);

namespace App\Actions\Domains;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionMessage;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\FrameworkMessage;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\EnvironmentOwner;
use Cbox\Id\Organization\Contracts\EnvironmentDomains;
use Cbox\Id\Organization\Exceptions\InvalidCustomDomain;

/**
 * Check the pending domain's TXT record and, when it is there, serve this environment on it.
 *
 * A record that is not visible yet is a refusal that says to wait, not a fault in the
 * domain: DNS takes minutes to propagate. A verified domain is recorded on the workspace's
 * trail as the workspace console has always recorded it (`organization.custom_domain_verified`).
 */
#[AsAction(
    name: 'domains.verify',
    summary: 'Look for the pending domain\'s DNS TXT record and, once it is visible, serve this environment on that domain.',
    scope: 'domains:write',
    danger: Danger::Write,
    schema: 'CustomDomain',
    tag: 'Domains',
    rest: ['POST', '/domains/verify'],
)]
final readonly class VerifyDomain implements Action
{
    public function __construct(
        private EnvironmentOwner $workspace,
        private EnvironmentDomains $domains,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $environment = $this->workspace->environment() ?? throw ActionRefused::notFound('environment');

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

        $this->workspace->record($context->principal, $environment->id, 'organization.custom_domain_verified', ['domain' => $result->domain]);

        $fresh = $environment->fresh() ?? $environment;

        return ActionResult::item($fresh, DomainFields::present($fresh, $this->domains));
    }
}
