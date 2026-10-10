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
use Cbox\Id\Federation\Contracts\SignInProviders;

/**
 * The social providers a sign-in page actually shows, after inheritance: the environment's,
 * one organization's own in place of them, minus the ones that organization turned off.
 *
 * The question "will Acme's people see a Google button, and whose credentials is it" has
 * three inputs, and a caller that combined them from the list itself would sooner or later
 * combine them differently from the sign-in page. This asks the same resolver the page does.
 */
#[AsAction(
    name: 'signin.social.offered',
    summary: 'The social login buttons one organization\'s sign-in page shows (or the plain sign-in page\'s), after inheritance from the environment, with where each comes from.',
    scope: 'signin:read',
    danger: Danger::Read,
    schema: 'OfferedSocialProviders',
    tag: 'Sign-in',
    rest: ['GET', '/sign-in/social-providers/offered'],
)]
final readonly class ListOfferedSocialProviders implements Action
{
    public function __construct(private SignInProviders $providers) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->max(64)->describe('The organization whose sign-in page to resolve. Left out, the plain sign-in page, before anybody has said who they are.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = OrganizationTarget::check($context, $context->nullableString('organization_id'));

        return ActionResult::item($organizationId, SocialProviderFields::offered($this->providers, $organizationId));
    }
}
