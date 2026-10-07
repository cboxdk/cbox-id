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
 * The social sign-in providers enabled in this environment — catalogue connections only
 * (Google, GitHub, Apple…), never a company's own SSO connection, which is a different job
 * on a different page.
 */
#[AsAction(
    name: 'signin.social.list',
    summary: 'List the social sign-in providers (Google, GitHub, Apple…) enabled in this environment, optionally for one organization.',
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
            Field::string('organization_id')->max(64)->describe('Only this organization\'s providers.'),
            ...self::pageFields(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = OrganizationTarget::check($context, $context->nullableString('organization_id'));

        return $this->page(
            Connection::query()
                ->whereNotNull('provider')
                ->when($organizationId !== null, fn (Builder $query): Builder => $query->where('organization_id', $organizationId)),
            $context,
            SocialProviderFields::present(...),
        );
    }
}
