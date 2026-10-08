<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Sso\CertificateExpiryAlerts;
use Illuminate\Console\Command;

/**
 * The daily SAML certificate scan ({@see CertificateExpiryAlerts}), scheduled in
 * routes/console.php. Safe to run by hand at any time: an alert already sent is not sent
 * again.
 */
final class CheckCertificateExpiryCommand extends Command
{
    protected $signature = 'cbox-id:sso:certificate-expiry';

    protected $description = 'Alert on SAML connection certificates that expire soon (webhook, audit trail, mail to the organization\'s admins)';

    public function handle(CertificateExpiryAlerts $alerts): int
    {
        $sent = $alerts->run();

        $this->info($sent === 1 ? '1 certificate expiry alert sent.' : "{$sent} certificate expiry alerts sent.");

        return self::SUCCESS;
    }
}
