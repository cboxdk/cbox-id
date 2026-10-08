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
use Cbox\Id\Federation\Exceptions\DomainAlreadyClaimed;
use Illuminate\Validation\ValidationException;

/**
 * Claim an email domain for an organization. Nothing routes on it yet: the answer carries a
 * DNS TXT record to publish, and `verify` promotes it once the record is visible. Claiming
 * the same domain again for the same organization answers with the claim it already made;
 * a domain another organization here already holds is `domain_taken`.
 */
#[AsAction(
    name: 'organizations.domains.add',
    summary: 'Claim an email domain (acme.com) for an organization. Returns the DNS TXT record to publish before verifying it.',
    scope: 'organizations:write',
    danger: Danger::Write,
    schema: 'OrganizationDomain',
    tag: 'Organizations',
    rest: ['POST', '/organizations/{organization_id}/domains'],
    status: 201,
    consoleRoutes: ['environment.organizations.domains.store'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class AddOrganizationDomain implements Action
{
    public function __construct(private DomainVerification $domains) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization id.'),
            Field::string('domain')->required()->max(190)->describe('A domain name — acme.com, not an address or a URL.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organization = OrganizationFields::find($context, $context->string('organization_id'));
        $domain = mb_strtolower(trim($context->string('domain')));

        if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain) !== 1) {
            throw ValidationException::withMessages(['domain' => 'Enter a domain name — acme.com, not an address or a URL.']);
        }

        try {
            $claimed = $this->domains->add($organization->id, $domain);
        } catch (DomainAlreadyClaimed) {
            throw new ActionRefused('domain_taken', 'That domain is already claimed.', 409, 'domain');
        }

        return ActionResult::item($claimed, DomainFields::present($claimed));
    }
}
