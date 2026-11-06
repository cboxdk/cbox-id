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
use Cbox\Id\Identity\Contracts\Subjects;
use Illuminate\Support\Facades\Validator;

#[AsAction(
    name: 'account.waitlists.join',
    summary: 'Join a published service waitlist with your verified email. Does not buy a plan or grant access.',
    scope: 'account:waitlists:write',
    danger: Danger::Write,
    plane: ActionPlane::Account,
    rest: ['POST', '/services/{program}/waitlist'],
    consoleRoutes: ['account.services.join'],
    consoleGate: ConsoleGate::Person,
    tag: 'Service waitlists',
)]
final readonly class JoinServiceWaitlist implements Action
{
    public function __construct(private ServiceAccessSettings $settings, private BillingWaitlists $billing, private Subjects $subjects) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::integer('program')->inPath()->min(1),
            Field::string('country')->nullable()->max(2)->describe('Optional two-letter country code.'),
            Field::object('answers', [])->max(20)->describe('Answers by the programme’s question keys, at most 2000 characters each.'),
            Field::string('reference')->required()->format('uuid')->max(36)->describe('A stable UUID for this signup attempt, also used for Billing idempotency.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        if (! $this->settings->enabled()) {
            throw ActionRefused::notFound('service directory');
        }
        $subject = $this->subjects->find(AsPerson::subjectId($context->principal)) ?? throw ActionRefused::notFound('account');
        if (! $subject->emailVerified || $subject->email === null) {
            throw ActionRefused::because('email_unverified', __('service_access.verify_email'), 'waitlist');
        }
        Validator::make($context->input, ['country' => ['nullable', 'regex:/^[A-Za-z]{2}$/'], 'answers.*' => ['nullable', 'string', 'max:2000'], 'reference' => ['uuid']])->validate();
        $answers = [];
        foreach ($context->array('answers') as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $answers[$key] = $value;
            }
        }
        $country = $context->nullableString('country');
        try {
            $this->billing->join((int) $context->string('program'), $subject->id, $subject->email, $country === null ? null : strtoupper($country), $answers, $context->string('reference'));
        } catch (ServiceAccessUnavailable) {
            throw new ActionRefused('waitlist_unavailable', __('service_access.unavailable'), 503, 'waitlist');
        }

        return ActionResult::item(null, ['outcome' => 'joined']);
    }
}
