<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Illuminate\Database\Eloquent\Builder;

/**
 * The email domains organizations have claimed: how a person's address finds the
 * connection they sign in through, and — captured — the rule that they must.
 *
 * A domain belongs to an ORGANIZATION, not to one connection: it routes to whichever of
 * the organization's connections is active.
 */
#[AsAction(
    name: 'sso.domains.list',
    summary: 'List the email domains organizations claimed for single sign-on, whether each is verified and captured, and the DNS record that proves an unverified one.',
    scope: 'sso:read',
    danger: Danger::Read,
    schema: 'SsoDomain',
    tag: 'Single sign-on',
    rest: ['GET', '/sso/domains'],
)]
final class ListSsoDomains implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of([
            EnterpriseReach::narrowField(list: true),
            ...self::pageFields(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = EnterpriseReach::narrowedTo($context);

        return $this->page(
            VerifiedDomain::query()->when($organizationId !== null, fn (Builder $query): Builder => $query->where('organization_id', $organizationId)),
            $context,
            SsoFields::presentDomain(...),
        );
    }
}
