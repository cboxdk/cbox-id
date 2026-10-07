<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Models\VerifiedDomain;

/**
 * The email domains an organization claims, verified or not, with the DNS record that
 * proves each one. A handful at most, so there is no page to turn.
 */
#[AsAction(
    name: 'organizations.domains.list',
    summary: 'List the email domains an organization claims, whether each is verified and captured, and the DNS TXT record that proves it.',
    scope: 'organizations:read',
    danger: Danger::Read,
    schema: 'OrganizationDomain',
    tag: 'Organizations',
    rest: ['GET', '/organizations/{organization_id}/domains'],
)]
final readonly class ListOrganizationDomains implements Action
{
    public function __construct(private DomainVerification $domains) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organization = OrganizationFields::find($context, $context->string('organization_id'));
        $domains = $this->domains->forOrganization($organization->id);

        return ActionResult::items($domains, array_map(static fn (VerifiedDomain $domain): array => DomainFields::present($domain), $domains));
    }
}
