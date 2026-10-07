<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Federation\Contracts\DomainVerification;

/**
 * Look for a claimed domain's DNS TXT record and mark it verified when it is there. Not
 * there yet is `record_not_found` — publish it and ask again; DNS takes its time. A domain
 * already verified answers as it is.
 */
#[AsAction(
    name: 'organizations.domains.verify',
    summary: 'Check a claimed domain\'s DNS TXT record and mark the domain verified if it is published.',
    scope: 'organizations:write',
    danger: Danger::Write,
    schema: 'OrganizationDomain',
    tag: 'Organizations',
    rest: ['POST', '/organizations/{organization_id}/domains/{domain_id}/verify'],
    consoleRoutes: ['environment.organizations.domains.verify'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class VerifyOrganizationDomain implements Action
{
    public function __construct(private DomainVerification $domains) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization id.'),
            Field::string('domain_id')->inPath()->max(64)->describe('The claimed domain\'s id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organization = OrganizationFields::find($context, $context->string('organization_id'));
        $domain = DomainFields::find($organization->id, $context->string('domain_id'));

        if (! $this->domains->verify($domain->id)) {
            throw ActionRefused::because('record_not_found', 'Verification failed — the DNS TXT record was not found yet.', 'domain');
        }

        $fresh = $domain->fresh() ?? $domain;

        return ActionResult::item($fresh, DomainFields::present($fresh));
    }
}
