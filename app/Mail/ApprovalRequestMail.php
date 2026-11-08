<?php

declare(strict_types=1);

namespace App\Mail;

use App\Platform\Agents\ApprovalMail;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * AN APPROVAL IS WAITING FOR YOU — for the person with no phone to tell them.
 *
 * Sent by {@see ApprovalMail} when an app's backchannel (CIBA) request, or an action a key's
 * approval policy held, is waiting on somebody that no enrolled Cbox Authenticator can reach.
 *
 * WHAT IT DOES NOT SAY is what the request is for. The push to the phone leaves the binding
 * message out because a lock screen is readable by anybody holding it; an inbox preview, a
 * smartwatch and a mail gateway's log are the same exposure. The binding message is the
 * sentence the person decides on, and they read it on the Approvals page, signed in. The
 * mail names who is asking and when it lapses — enough to know whether to open it.
 */
final class ApprovalRequestMail extends BrandedMailable
{
    public function __construct(
        public string $app,
        public string $url,
        public DateTimeInterface $expiresAt,
    ) {}

    protected function subjectLine(): string
    {
        $subject = __('mail.approval_request.subject', ['app' => $this->app]);

        return is_string($subject) ? $subject : '';
    }

    protected function views(): array
    {
        return ['mail.approval-request', 'mail.text.approval-request'];
    }

    protected function data(): array
    {
        return ['time' => Carbon::instance($this->expiresAt)->utc()->format('H:i').' UTC'];
    }
}
