<?php

declare(strict_types=1);

namespace App\Platform\Agents;

use App\Mail\ApprovalRequestMail;
use App\Platform\Locale\MailLocale;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Support\DeviceConfig;
use Cbox\Id\Identity\Contracts\Subjects;
use DateTimeInterface;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * TELL THE PERSON AN APPROVAL IS WAITING, WHEN NO PHONE WILL.
 *
 * An app's backchannel (CIBA) request and an action a key's approval policy held are pushed
 * to the person's enrolled Cbox Authenticator by the devices module. Somebody who has not
 * installed it — the Android app is not on Google Play yet, a deployment may run without the
 * module — heard about the request only from the agent that asked, if it said anything.
 * This mails them a link to the page where they answer it.
 *
 * `cbox-id.approvals.email` decides when: `fallback` (the default) only when no enrolled
 * device can take the push, `always` for every request, `off` never.
 *
 * FAILS OPEN, like the push. A request that was filed stands whether or not the mail went:
 * the client polls, the Approvals page lists it. A mail server that is down must never turn
 * a successful request into a failed one.
 *
 * LIMITED PER PERSON. Any client holding the CIBA grant can ask, and outbound mail is the
 * abusable resource: a flood of approval mails trains the reader to approve without reading.
 * The request itself is still filed past the limit; only the mail is withheld.
 */
final readonly class ApprovalMail
{
    public const int ATTEMPTS = 5;

    public const int DECAY_SECONDS = 600;

    /** The client's name is the client's to choose; it is shown, never trusted, and kept short. */
    private const int APP_NAME_MAX = 60;

    public function __construct(
        private Subjects $subjects,
        private MailLocale $locale,
        private LoggerInterface $logger,
    ) {}

    /**
     * Mail $subjectId about one waiting request, if this deployment says to.
     *
     * Called in the realm the person is a subject of, with $url already on the host that
     * serves their Approvals page. $pushAllowed is false when the devices module would
     * refuse this client a push (its allowlist), so the fallback does not count a phone
     * that will stay silent.
     */
    public function notify(string $subjectId, string $app, string $url, DateTimeInterface $expiresAt, bool $pushAllowed = true): void
    {
        try {
            $mode = config('cbox-id.approvals.email', 'fallback');

            if ($mode === 'off' || $mode === false) {
                return;
            }

            if ($mode !== 'always' && $pushAllowed && $this->reachableByPush($subjectId)) {
                return;
            }

            $key = 'approval-mail:'.$subjectId;

            if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS)) {
                $this->logger->notice('Withheld an approval mail: too many for this person.', ['subject' => $subjectId]);

                return;
            }

            $subject = $this->subjects->find($subjectId);

            if ($subject === null || $subject->email === null || $subject->email === '') {
                return;
            }

            RateLimiter::hit($key, self::DECAY_SECONDS);

            Mail::to($subject->email)
                ->locale($this->locale->forRecipient(false))
                ->send(new ApprovalRequestMail(mb_strimwidth($app, 0, self::APP_NAME_MAX, '…'), $url, $expiresAt));
        } catch (Throwable $e) {
            $this->logger->warning('Could not mail an approval request.', [
                'subject' => $subjectId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /** Whether a push will reach one of this person's phones. */
    private function reachableByPush(string $subjectId): bool
    {
        if (! class_exists(Device::class) || ! DeviceConfig::bool('id-devices.enabled', false)) {
            return false;
        }

        return Device::query()->where('subject_id', $subjectId)->notifiable()->exists();
    }
}
