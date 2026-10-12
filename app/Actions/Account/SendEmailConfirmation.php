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
use App\Platform\Actions\Input\InputSchema;
use App\Platform\MemberEmailVerification;
use App\Platform\VerifiedEmailGate;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Mail yourself a link that confirms your email address.
 *
 * The console refuses writes from an unconfirmed address ({@see VerifiedEmailGate})
 * and its refusal said "send a new one from your account page" — where no such button
 * existed. A workspace owner on cboxid.com hit exactly that: refused, told to click
 * something that was not there. This is the something, behind the console's banner and on
 * the API for a person's own token.
 *
 * Limited per person, like the signup resend: outbound mail is the abusable resource.
 */
#[AsAction(
    name: 'account.email.confirmation.send',
    summary: 'Mail yourself a link that confirms your email address.',
    scope: 'account:profile:write',
    danger: Danger::Write,
    plane: ActionPlane::Account,
    rest: ['POST', '/email/confirmation'],
    consoleRoutes: ['account.email.confirmation'],
    consoleGate: ConsoleGate::Person,
    tag: 'Profile',
)]
final readonly class SendEmailConfirmation implements Action
{
    public const int ATTEMPTS = 3;

    public const int DECAY_SECONDS = 600;

    public function __construct(
        private MemberEmailVerification $verification,
        private Subjects $subjects,
        private PlatformRoot $platformRoot,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $subjectId = AsPerson::subjectId($context->principal);
        $subject = $this->platformRoot->run(fn () => $this->subjects->find($subjectId))
            ?? throw ActionRefused::notFound('account');

        if ($subject->emailVerified) {
            return ActionResult::item($subject, ['outcome' => 'already_confirmed', 'email' => $subject->email]);
        }

        $key = 'email-confirmation:'.$subjectId;

        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS)) {
            throw new ActionRefused('too_soon', 'We have sent a few links already. Try again in '
                .RateLimiter::availableIn($key).' seconds, or check your spam folder in the meantime.', 429);
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);
        $this->verification->sendConfirmation($subject);

        return ActionResult::item($subject, ['outcome' => 'sent', 'email' => $subject->email]);
    }
}
