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
use Cbox\Id\Federation\Exceptions\DomainNotVerified;

/**
 * Turn capture on or off for a claimed domain. With capture on, everyone signing in with an
 * address on the domain is routed to the organization's SSO connection.
 *
 * An explicit state rather than a toggle, so a retried request cannot flip it back. Only a
 * VERIFIED domain may capture (`domain_not_verified`): on an unproven one, capture is one
 * tenant claiming addresses it does not own. Turning it off is always allowed.
 *
 * CRITICAL: it changes how a whole domain's people sign in.
 */
#[AsAction(
    name: 'organizations.domains.capture',
    summary: 'Turn capture on or off for a verified domain: with it on, everyone with an address there signs in through the organization\'s SSO.',
    scope: 'organizations:write',
    danger: Danger::Critical,
    schema: 'OrganizationDomain',
    tag: 'Organizations',
    rest: ['PUT', '/organizations/{organization_id}/domains/{domain_id}/capture'],
    consoleRoutes: ['environment.organizations.domains.capture'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class SetDomainCapture implements Action
{
    public function __construct(private DomainVerification $domains) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization id.'),
            Field::string('domain_id')->inPath()->max(64)->describe('The claimed domain\'s id.'),
            Field::boolean('enabled')->required()->describe('Whether the domain captures sign-ins.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organization = OrganizationFields::find($context, $context->string('organization_id'));
        $domain = DomainFields::find($organization->id, $context->string('domain_id'));
        $enabled = $context->boolean('enabled');

        if ($domain->capture !== $enabled) {
            try {
                $this->domains->setCapture($domain->id, $enabled);
            } catch (DomainNotVerified) {
                throw ActionRefused::because('domain_not_verified', 'Verify the domain before turning capture on.', 'domain');
            }
        }

        $fresh = $domain->fresh() ?? $domain;

        return ActionResult::item($fresh, DomainFields::present($fresh));
    }
}
