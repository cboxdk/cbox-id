<?php

declare(strict_types=1);

namespace App\Actions\SignIn;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\OrganizationTarget;
use App\Platform\Actions\Paginates;
use Cbox\Id\Federation\Models\Connection;
use Illuminate\Database\Eloquent\Builder;

/**
 * The social login providers set up in this environment — the environment's own, which
 * every organization inherits, and organizations' own — catalogue connections only
 * (Google, GitHub, Apple…), never a company's own SSO connection, which is a different job
 * on a different page.
 */
#[AsAction(
    name: 'signin.social.list',
    summary: 'List the social login providers (Google, GitHub, Apple…) set up in this environment — the environment\'s own and organizations\' — optionally for one organization.',
    scope: 'signin:read',
    danger: Danger::Read,
    schema: 'SocialProvider',
    tag: 'Sign-in',
    rest: ['GET', '/sign-in/social-providers'],
)]
final class ListSocialProviders implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->max(64)->describe('Only this organization\'s own providers (not the ones it inherits — see signin.social.offered).'),
            Field::string('level')->oneOf(['environment', 'organization'])->describe('Only the environment\'s providers, which every organization inherits, or only organizations\' own.'),
            ...self::pageFields(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = OrganizationTarget::check($context, $context->nullableString('organization_id'));

        return $this->page(
            Connection::query()
                ->whereNotNull('provider')
                ->when($organizationId !== null, fn (Builder $query): Builder => $query->where('organization_id', $organizationId))
                ->when($context->string('level') === 'environment', fn (Builder $query): Builder => $query->whereNull('organization_id'))
                ->when($context->string('level') === 'organization', fn (Builder $query): Builder => $query->whereNotNull('organization_id')),
            $context,
            SocialProviderFields::present(...),
        );
    }
}
