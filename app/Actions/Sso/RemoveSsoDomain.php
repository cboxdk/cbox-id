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

/**
 * Give up an organization's claim on an email domain. Its people stop being routed to
 * the organization's connection, and the domain is free for whoever proves it next.
 */
#[AsAction(
    name: 'sso.domains.delete',
    summary: 'Remove a claimed email domain. Its people stop being routed to the organization\'s SSO connection.',
    scope: 'sso:write',
    danger: Danger::Destructive,
    tag: 'Enterprise SSO',
    rest: ['DELETE', '/sso/domains/{id}'],
    status: 204,
    consoleRoutes: ['connections.domains.destroy', 'environment.connections.domains.destroy'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class RemoveSsoDomain implements Action
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

        $this->domains->remove($domain->id);

        return ActionResult::none($domain);
    }
}
