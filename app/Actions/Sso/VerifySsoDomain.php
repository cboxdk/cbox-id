<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Models\VerifiedDomain;

/**
 * Look for a claimed domain's DNS TXT record now. Found, the domain is verified and
 * starts routing its people to the organization's connection; not found yet is an answer
 * too (`verified: false`) rather than an error — DNS can take a few minutes.
 */
#[AsAction(
    name: 'sso.domains.verify',
    summary: 'Check a claimed domain\'s DNS TXT record now. Answers the domain, with verified true once the record is found.',
    scope: 'sso:write',
    danger: Danger::Write,
    schema: 'SsoDomain',
    tag: 'Enterprise SSO',
    rest: ['POST', '/sso/domains/{id}/verify'],
    consoleRoutes: ['connections.domains.verify', 'environment.connections.domains.verify'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class VerifySsoDomain implements Action
{
    public function __construct(private DomainVerification $domains) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The domain\'s id.'),
            EnterpriseReach::narrowField(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $domain = SsoFields::domain($context);

        $this->domains->verify($domain->id);

        $fresh = VerifiedDomain::query()->whereKey($domain->id)->first() ?? $domain;

        return ActionResult::item($fresh, SsoFields::presentDomain($fresh));
    }
}
