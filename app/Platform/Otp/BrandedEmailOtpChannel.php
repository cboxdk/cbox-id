<?php

declare(strict_types=1);

namespace App\Platform\Otp;

use App\Mail\MailBrands;
use App\Mail\OneTimeCodeMail;
use App\Platform\Locale\HostedLocale;
use App\Platform\Locale\MailLocale;
use Cbox\Id\Otp\Channels\EmailOtpChannel;
use Cbox\Id\Otp\Contracts\OtpChannel;
use Cbox\Id\Otp\ValueObjects\OtpDelivery;
use Illuminate\Support\Facades\Mail;

/**
 * THE `email` ONE-TIME-CODE CHANNEL, as Cbox ID sends it: a branded, translated, two-part
 * {@see OneTimeCodeMail} in place of the framework's plain-text {@see EmailOtpChannel}.
 *
 * The framework's channel is deliberately generic — one English sentence, no HTML, a
 * subject from config — because the framework cannot know whose brand a code goes out in
 * or what the code is for. Cbox ID can, and every code-by-email flow goes through the
 * framework's OTP service (the Radar step-up, a challenged sign-up, device approval,
 * linking a sign-in method), so the person met the vendor's sign-in page and then an
 * unbranded English mail from somebody else asking them to type a number.
 *
 * WIRED THROUGH THE FRAMEWORK'S OWN EXTENSION POINT, not a fork: `cbox-id.otp.channels`
 * maps the `email` key to this class (config/cbox-id.php), and the framework's channel
 * registry resolves it from the container like any other channel. Nothing upstream had to
 * change, and a code issued by framework code (`OtpService::issue(…, 'email')`) arrives
 * here the same as one issued by the app.
 *
 * WHAT IT READS FROM THE DELIVERY: the recipient, the code, the PURPOSE (which flow — so
 * the mail can say what the code is for, and what to do if it was not you), the lifetime,
 * and the LOCALE the request was served in — the hosted page the person was on, which is
 * the language they asked in. A locale outside the hosted six falls back to the
 * environment's default, as an administrator-initiated mail does ({@see MailLocale}).
 *
 * The plaintext code is handed to the mailable and the mailer and nothing else: it is not
 * logged, stored or put in the subject ({@see OneTimeCodeMail} says why not the subject).
 *
 * `CBOX_ID_OTP_EMAIL_FROM_ADDRESS` still chooses the address, for a deployment that sends
 * codes from a separately aligned sender; the NAME on it is the brand's.
 * `CBOX_ID_OTP_EMAIL_SUBJECT` is the framework channel's and is not read here — the subject
 * is per purpose, per language.
 */
final readonly class BrandedEmailOtpChannel implements OtpChannel
{
    public function __construct(
        private MailBrands $brands,
        private MailLocale $locales,
    ) {}

    public function deliver(OtpDelivery $delivery): void
    {
        $mail = (new OneTimeCodeMail($delivery->code, $delivery->purpose, $delivery->ttlMinutes()))
            ->inBrand($this->brands->current());

        $this->applySender($mail);

        // The mailer is asked for at send time, not injected: the channel is cached for the
        // life of the registry, and the mailer it would have captured is not necessarily the
        // one in place when the code is sent.
        Mail::to($delivery->recipient)
            ->locale(HostedLocale::fromTag($delivery->locale)->value ?? $this->locales->forRecipient())
            ->send($mail);
    }

    /**
     * The framework's OTP sender settings, where a deployment set them. The address wins
     * outright (it is an alignment choice); the brand's sender name wins over the
     * deployment's configured one, which is the point of a brand.
     */
    private function applySender(OneTimeCodeMail $mail): void
    {
        $address = config('cbox-id.otp.email.from.address');
        $configuredName = config('cbox-id.otp.email.from.name');
        $configuredName = is_string($configuredName) && $configuredName !== '' ? $configuredName : null;
        $name = $mail->brand()->senderName ?? $configuredName;

        if (is_string($address) && $address !== '') {
            $fallback = config('mail.from.name');
            $mail->from($address, $name ?? (is_string($fallback) ? $fallback : null));

            return;
        }

        $default = config('mail.from.address');

        if ($mail->brand()->senderName === null && $configuredName !== null && is_string($default) && $default !== '') {
            $mail->from($default, $configuredName);
        }
    }
}
