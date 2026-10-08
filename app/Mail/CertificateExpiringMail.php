<?php

declare(strict_types=1);

namespace App\Mail;

use App\Platform\Sso\CertificateExpiryAlerts;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your single sign-on stops working on …" — to an organization's owners and administrators,
 * from the daily certificate scan ({@see CertificateExpiryAlerts}).
 *
 * Written for whoever administers the organization, who is usually not whoever runs its
 * identity provider: it says what will happen, when, and what to ask for — the identity
 * provider's new signing certificate — rather than assuming they know what one is.
 */
final class CertificateExpiringMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $organization,
        public string $connectionName,
        public CarbonInterface $expiresAt,
        public int $daysRemaining,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->daysRemaining < 0
                ? __('mail.certificate_expiring.subject_expired', ['connection' => $this->connectionName, 'brand' => MailText::brand()])
                : trans_choice('mail.certificate_expiring.subject', max(0, $this->daysRemaining), [
                    'connection' => $this->connectionName,
                    'brand' => MailText::brand(),
                ]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.certificate-expiring', with: [
            'brand' => MailText::brand(),
            'expiresOn' => $this->expiresAt->copy()->locale(app()->getLocale())->isoFormat('LL'),
            'expired' => $this->daysRemaining < 0,
        ]);
    }
}
