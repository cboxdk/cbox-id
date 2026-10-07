<?php

declare(strict_types=1);

namespace App\Actions\Workspace\Projects;

use App\Actions\Workspace\InWorkspace;
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
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\ValueObjects\Subject;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Re-send the signup confirmation the workspace's first environment is waiting on — the
 * only way back for an owner whose email was lost, filtered or expired.
 *
 * NO ADDRESS IS ACCEPTED. From the console it mails the person signed in; from a key —
 * which is nobody's address — it mails the workspace's OWNER, whose confirmation is the
 * one the environment waits for. There is deliberately no parameter anybody could point
 * somewhere else.
 *
 * Outbound mail is the scarce, abusable resource, so it is limited to three in ten minutes
 * per PERSON mailed — whichever door asks, one budget — and the fourth is `too_soon`.
 */
#[AsAction(
    name: 'projects.verification.resend',
    summary: 'Re-send the signup confirmation the workspace\'s first environment is waiting on, to the owner\'s address on file.',
    scope: 'projects:write',
    danger: Danger::Write,
    plane: ActionPlane::Workspace,
    rest: ['POST', '/projects/verification/resend'],
    consoleRoutes: ['projects.verification.resend'],
    consoleGate: ConsoleGate::WorkspaceMember,
    tag: 'Projects',
)]
final readonly class ResendVerification implements Action
{
    public const int ATTEMPTS = 3;

    public const int DECAY_SECONDS = 600;

    public function __construct(
        private MemberEmailVerification $verification,
        private Subjects $subjects,
        private Memberships $memberships,
        private PlatformRoot $platformRoot,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspaceId = InWorkspace::id($context->principal);
        $subject = $this->recipient($workspaceId, InWorkspace::personId($context->principal))
            ?? throw ActionRefused::notFound('owner');

        // Keyed on the SUBJECT, not the address or the caller: the recipient is who the
        // budget protects, and every door shares it.
        $key = 'organization-verify-resend|'.$subject->id;

        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS)) {
            throw new ActionRefused('too_soon', 'That is a lot of emails. Try again in '
                .RateLimiter::availableIn($key).' seconds, or check your spam folder in the meantime.', 429);
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        $outcome = $this->verification->resend($subject);

        return ActionResult::item($outcome, [
            'outcome' => $outcome->value,
            'message' => $outcome->message($subject->email ?? ''),
        ]);
    }

    /** The person signed in, or — for a key — the workspace's owner. */
    private function recipient(string $workspaceId, ?string $personId): ?Subject
    {
        return $this->platformRoot->run(function () use ($workspaceId, $personId): ?Subject {
            $subjectId = $personId ?? $this->memberships->forOrganization($workspaceId)
                ->first(static fn (Membership $member): bool => $member->role === MembershipRole::Owner)
                ?->user_id;

            return is_string($subjectId) ? $this->subjects->find($subjectId) : null;
        });
    }
}
