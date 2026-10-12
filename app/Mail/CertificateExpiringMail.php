<?php

declare(strict_types=1);

namespace App\Mail;

use App\Platform\Sso\CertificateExpiryAlerts;
use Carbon\CarbonInterface;

/**
 * "Your single sign-on stops working on …" — to an organization's owners and administrators,
 * from the daily certificate scan ({@see CertificateExpiryAlerts}).
 *
 * Written for whoever administers the organization, who is usually not whoever runs its
 * identity provider: it says what will happen, when, and what to ask for — the identity
 * provider's new signing certificate — rather than assuming they know what one is.
 */
final class CertificateExpiringMail extends BrandedMailable
{
    public function __construct(
        public string $organization,
        public string $connectionName,
        public CarbonInterface $expiresAt,
        public int $daysRemaining,
    ) {}

    protected function subjectLine(): string
    {
        $subject = $this->daysRemaining < 0
            ? __('mail.certificate_expiring.subject_expired', ['connection' => $this->connectionName, 'brand' => $this->brand()->name])
            : trans_choice('mail.certificate_expiring.subject', max(0, $this->daysRemaining), [
                'connection' => $this->connectionName,
                'brand' => $this->brand()->name,
            ]);

        return is_string($subject) ? $subject : '';
    }

    protected function views(): array
    {
        return ['mail.certificate-expiring', 'mail.text.certificate-expiring'];
    }

    protected function data(): array
    {
        return [
            'expiresOn' => $this->expiresAt->copy()->locale(app()->getLocale())->isoFormat('LL'),
            'expired' => $this->daysRemaining < 0,
        ];
    }
}
