<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Listeners;

use Cbox\Id\Devices\Contracts\PushDispatcher;
use Cbox\Id\Devices\Enums\NotificationKind;
use Cbox\Id\Devices\Support\DeviceConfig;
use Cbox\Id\Devices\ValueObjects\PushPayload;
use Cbox\Id\Kernel\Events\EventDelivered;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tells a user's handsets that something happened to their account.
 *
 * Unlike approval prompts, these DO ride the ordinary event relay. Nobody is sitting
 * waiting on "you signed in on a new device", so the up-to-a-minute delay the outbox
 * costs is the right trade for staying off the login critical path entirely.
 *
 * WHAT CAN AND CANNOT BE ALERTED ON
 * ---------------------------------
 * Only types actually emitted to the `events` outbox reach this listener. That set
 * includes `user.login`, `user.session_started`, `user.session_revoked` and
 * `identity.linked`. It does NOT include `user.password_changed`, `user.locked_out`,
 * `user.mfa_enrolled` or `user.passkey_registered` — those exist only as audit-log
 * actions, and reaching them would mean decorating the hash-chained audit log, whose
 * appends are serialised on a single anchor row. That cost has not been paid, so a
 * config listing an audit-only action will simply never fire rather than erroring.
 *
 * Delivery is at-least-once, so this may run twice for the same event. A duplicate
 * alert is a cosmetic annoyance rather than a correctness problem, which is why there
 * is no dedupe here; an approval prompt would deserve one.
 */
final class SendSecurityAlert
{
    /** Human wording per event type. Anything not listed gets a generic line. */
    private const MESSAGES = [
        'user.login' => 'Your account was signed in to.',
        'user.session_started' => 'A new sign-in session was started.',
        'user.session_revoked' => 'A sign-in session was signed out.',
        'user.sessions_revoked' => 'All other sessions were signed out.',
        'identity.linked' => 'A new sign-in method was linked to your account.',
    ];

    public function __construct(
        private readonly PushDispatcher $dispatcher,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(EventDelivered $delivered): void
    {
        try {
            if (! DeviceConfig::bool('id-devices.enabled', false)) {
                return;
            }

            $type = $delivered->event->type;

            if (! in_array($type, DeviceConfig::strings('id-devices.alerts'), true)) {
                return;
            }

            $subjectId = $this->subjectFrom($delivered);

            if ($subjectId === null) {
                return;
            }

            // A deadline even though nobody is waiting on it. A day-old "you signed in"
            // is not worth pushing, and — more importantly — an undeadlined notification
            // parked by an open circuit breaker never terminalises and would accumulate
            // until it starved the retry sweep. See config/id-devices.php.
            $ttl = max(60, DeviceConfig::int('id-devices.alert_ttl_seconds', 86400));

            $this->dispatcher->dispatch(
                subjectId: $subjectId,
                kind: NotificationKind::SecurityAlert,
                expiresAt: Carbon::now()->addSeconds($ttl),
                payload: new PushPayload(
                    title: 'Cbox ID',
                    // As with approvals, the lock screen stays general. Where and from
                    // what address is account-sensitive; it is read in the app.
                    body: self::MESSAGES[$type] ?? 'There was activity on your account.',
                    data: ['kind' => NotificationKind::SecurityAlert->value, 'event' => $type],
                ),
            );
        } catch (Throwable $e) {
            // The relay catches listener throws and retries the whole event, which would
            // re-run every other listener too. An alert is not worth that, so it is
            // swallowed here.
            $this->logger->warning('Could not send a device security alert.', [
                'event_id' => $delivered->event->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The subject an event concerns. Payload key names are not uniform across the
     * emitting subsystems, so the plausible ones are tried in order.
     */
    private function subjectFrom(EventDelivered $delivered): ?string
    {
        $payload = $delivered->event->payload;

        foreach (['user_id', 'subject_id', 'id'] as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
