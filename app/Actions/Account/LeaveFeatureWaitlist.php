<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\ServiceAccess\BillingWaitlists;
use App\Platform\ServiceAccess\ServiceAccessSettings;
use App\Platform\ServiceAccess\ServiceAccessUnavailable;
use App\Platform\ServiceAccess\WaitlistTeams;

#[AsAction(
    name: 'account.waitlists.leave',
    summary: 'Withdraw your own feature interest or beta application for a team. Active grants are managed by the service.',
    scope: 'account:waitlists:write',
    danger: Danger::Write,
    plane: ActionPlane::Account,
    rest: ['POST', '/services/{program}/waitlist/leave'],
    consoleRoutes: ['account.services.leave'],
    consoleGate: ConsoleGate::Person,
    tag: 'Service waitlists',
)]
final readonly class LeaveFeatureWaitlist implements Action
{
    public function __construct(private ServiceAccessSettings $settings, private BillingWaitlists $billing, private WaitlistTeams $teams) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::integer('program')->inPath()->min(1),
            Field::string('organization')->required()->max(64),
            Field::string('reference')->required()->format('uuid')->max(36),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        if (! $this->settings->enabled()) {
            throw ActionRefused::notFound('service directory');
        }
        $subject = AsPerson::subjectId($context->principal);
        $organization = $context->string('organization');
        $this->teams->assertMember($subject, $organization);
        try {
            $this->billing->leave((int) $context->string('program'), $subject, $organization, $context->string('reference'));
        } catch (ServiceAccessUnavailable) {
            throw new ActionRefused('waitlist_unavailable', __('service_access.unavailable'), 503, 'waitlist');
        }

        return ActionResult::item(null, ['outcome' => 'withdrawn']);
    }
}
