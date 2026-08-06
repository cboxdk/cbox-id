<?php

declare(strict_types=1);

namespace App\Platform;

use App\Mail\EmailVerificationMail;
use App\Platform\Enums\VerificationResendOutcome;
use Cbox\Id\Identity\Contracts\EmailVerification;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\EmailVerificationToken;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Mail;

/**
 * Re-send the signup confirmation to an account member's OWN address.
 *
 * WHY THIS EXISTS. {@see SignupProvisioner} holds an account's first environment back
 * until the owner proves their address, which makes a bot signup worthless — and makes a
 * lost or expired email fatal for a real one. The account, the member and the project are
 * all there; the only route to an environment is a link in an inbox that no longer has
 * it, and the 24-hour token has to expire before the same address can even sign up again.
 * This is the way out.
 *
 * WHY APP-LAYER. The package's {@see EmailVerification} contract offers `issue()` and
 * `verify()` and nothing else — there is no resend primitive to reuse, and the three
 * things that make a resend safe (bind it to the caller's own identity, retire the
 * previous link, stay silent about verification state) are all decisions about THIS
 * product's signup flow rather than about token mechanics.
 *
 * The member is passed in as a MODEL, never an address. There is no parameter here for a
 * caller to steer: the address mailed is read off the member row the session resolved,
 * so "resend to someone else" is not an input this action can be given. The throttle is
 * the caller's job, and lives at the control (see the projects page) — same shape
 * as the OTP step-up resend, where the component throttles and the service acts.
 */
final class MemberEmailVerification
{
    public function __construct(
        private readonly EmailVerification $verification,
        private readonly Subjects $subjects,
        private readonly PlatformRoot $platformRoot,
        private readonly MailLinks $links,
    ) {}

    /**
     * Issue a new confirmation link and mail it to the member's address, retiring every
     * link issued before it.
     *
     * Every refusal is a quiet no-op rather than an exception: this is reachable by a
     * button click, and a member who clicks it twice, or clicks it on an account whose
     * environment landed while the page was open, has done nothing wrong.
     */
    public function resend(Membership $member): VerificationResendOutcome
    {
        $subjectId = $member->subject_id;

        // The environment is what the confirmation is FOR. Once it exists there is
        // nothing a new link could do, and re-mailing would be pure noise — including on
        // a replayed click after the first link already landed.
        if (Environment::query()->where('account_id', $member->account_id)->exists()) {
            return VerificationResendOutcome::AlreadyProvisioned;
        }

        // No subject means the first-install bootstrap window, where a token cannot be
        // bound to anyone (see SignupProvisioner). Reported as Sent, like every other
        // path that mails nothing, so the control never becomes an oracle.
        if (! is_string($subjectId) || $subjectId === '') {
            return VerificationResendOutcome::Sent;
        }

        // Account members are subjects in the PLATFORM ROOT, and both the subject lookup
        // and the token table are environment-owned — a query run under a tenant's scope
        // would silently see nothing and issue a token into the wrong environment.
        $url = $this->platformRoot->run(function () use ($subjectId, $member): ?string {
            // Already confirmed: send nothing. The caller still says "sent" — see
            // VerificationResendOutcome.
            if ($this->subjects->find($subjectId)?->emailVerified === true) {
                return null;
            }

            // Retire every live link for this subject BEFORE minting the next one. The
            // package's tokens are already single-use, but single-use is not the same as
            // single-LIVE: without this, five resends leave five working links in five
            // inboxes (or in whatever forwarded them), each one a standing grant. Scoped
            // to the subject rather than to the address, so a link confirming a
            // previously-held address dies with the rest.
            EmailVerificationToken::query()
                ->where('user_id', $subjectId)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            // MailLinks, not route(): this link is MAILED, and the confirmation token it
            // carries is a bearer credential — see that class.
            return $this->links->route('verification.verify', $this->verification->issue($subjectId, $member->email));
        });

        if (is_string($url)) {
            Mail::to($member->email)->send(new EmailVerificationMail($url));
        }

        return VerificationResendOutcome::Sent;
    }
}
