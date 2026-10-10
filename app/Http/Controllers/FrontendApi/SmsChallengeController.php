<?php

declare(strict_types=1);

namespace App\Http\Controllers\FrontendApi;

use App\Platform\FrontendApi\LoginTickets;
use App\Platform\PlatformAuth;
use Cbox\Id\FrontendApi\Models\PublishableKey;
use Cbox\Id\Otp\Exceptions\OtpRateLimitExceeded;
use Cbox\Id\Otp\Sms\Exceptions\SmsDeliveryFailed;
use Cbox\Id\Otp\Sms\Exceptions\SmsSendRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Text the sign-in code for an embedded second factor — the step before
 * {@see SecondFactorController} is called with `method: sms`.
 *
 * A SEND SPENDS ONE OF THE TICKET'S FIVE ATTEMPTS, like a guess does. That is the bound on
 * how many texts one proved password can cause through this door, on top of the SMS guard
 * (cooldown, per-number, per-IP and daily caps) and the OTP issue caps every send passes.
 * A public page is exactly where SMS pumping is attempted, and the ticket is the only
 * thing here that proves anything.
 *
 * The answer names the number MASKED and nothing else about the person. A refusal is one
 * shape whatever the reason, with a retry hint when waiting would help.
 */
class SmsChallengeController
{
    public function __construct(
        private readonly PlatformAuth $auth,
        private readonly LoginTickets $tickets,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $key = $request->attributes->get('cbox_publishable_key');
        $token = $request->input('mfa_token');

        if (! $key instanceof PublishableKey || ! is_string($token) || $token === '') {
            return $this->refuse();
        }

        $ticket = $this->tickets->claimAttempt($token, 'pending_mfa', $key);

        if ($ticket === null) {
            return $this->refuse();
        }

        $this->auth->holdForMfa($request, $ticket->subject_id);

        try {
            $sent = $this->auth->sendSmsChallenge($request, $request->getPreferredLanguage(['en', 'da', 'de', 'fr', 'nb', 'sv']));
        } catch (OtpRateLimitExceeded $limited) {
            return $this->rateLimited($limited->retryAfterSeconds);
        } catch (SmsSendRefused $refused) {
            return $refused->reason->isTemporary() ? $this->rateLimited($refused->retryAfterSeconds) : $this->refuse();
        } catch (SmsDeliveryFailed $failed) {
            report($failed);

            return new JsonResponse(['status' => 'unavailable'], 503);
        }

        if ($sent === null) {
            return $this->refuse();
        }

        return new JsonResponse([
            'status' => 'sent',
            'to' => $sent->maskedNumber,
            'expires_in' => max(0, $sent->expiresAt->getTimestamp() - time()),
        ]);
    }

    private function rateLimited(int $retryAfter): JsonResponse
    {
        return new JsonResponse(['status' => 'rate_limited', 'retry_after' => max(1, $retryAfter)], 429);
    }

    private function refuse(): JsonResponse
    {
        return new JsonResponse(['status' => 'invalid'], 401);
    }
}
