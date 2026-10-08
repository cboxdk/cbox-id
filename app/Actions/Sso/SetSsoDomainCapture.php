<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\Federation\Contracts\DomainVerification;

/**
 * Capture a verified domain — everybody with an address at it must sign in through the
 * organization's SSO, whatever account they already had — or release it.
 *
 * Only a VERIFIED domain: capturing an address space nobody has proven control of would
 * let a claim on somebody else's domain take its people's sign-in. The state is said
 * explicitly (`capture: true|false`) rather than toggled, so a retry cannot undo itself.
 */
#[AsAction(
    name: 'sso.domains.capture',
    summary: 'Turn capture on or off for a verified domain: captured, everyone with an address at it must sign in through the organization\'s SSO.',
    scope: 'sso:write',
    danger: Danger::Critical,
    schema: 'SsoDomain',
    tag: 'Enterprise SSO',
    rest: ['POST', '/sso/domains/{id}/capture'],
    consoleRoutes: ['connections.domains.capture', 'environment.connections.domains.capture'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class SetSsoDomainCapture implements Action
{
    public function __construct(private DomainVerification $domains) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The domain\'s id.'),
            Field::boolean('capture')->required()->describe('true to capture the domain, false to release it.'),
            EnterpriseReach::narrowField(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $domain = SsoFields::domain($context);

        if (! $domain->isVerified()) {
            throw new ActionRefused('domain_unverified', 'Verify the domain before capturing it.', 403, 'capture');
        }

        $this->domains->setCapture($domain->id, $context->boolean('capture'));

        $domain->refresh();

        return ActionResult::item($domain, SsoFields::presentDomain($domain));
    }
}
