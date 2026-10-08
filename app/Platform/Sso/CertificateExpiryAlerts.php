<?php

declare(strict_types=1);

namespace App\Platform\Sso;

use App\Mail\CertificateExpiringMail;
use App\Models\CertificateAlert;
use App\Platform\Enterprise\EnterpriseAudit;
use App\Platform\Locale\HostedLocales;
use Carbon\CarbonImmutable;
use Cbox\Id\Federation\Enums\ConnectionStatus;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Kernel\Events\Contracts\EventBus;
use Cbox\Id\Kernel\Events\ValueObjects\DomainEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Mail;

/**
 * THE DAILY QUESTION NOBODY REMEMBERS TO ASK: does any SAML connection's signing certificate
 * stop working soon?
 *
 * An identity provider's certificate expires on a date set a year or three ago by somebody
 * who has since left, and the first anybody hears of it is a whole organization unable to
 * sign in. So once a day, in every active environment, every ACTIVE SAML connection is read
 * for when it stops working — the latest expiry among the certificates it trusts, so a
 * renewal already staged means there is nothing to say ({@see ConnectionCertificates::effectiveExpiry()})
 * — and at each threshold (30 and 7 days by default, `cbox-id.portal.certificate_alerts`) it
 * says so, ONCE per certificate and threshold:
 *
 *  - a `connection.certificate_expiring` event, delivered to every webhook subscribed to it;
 *  - an entry on the organization's trail;
 *  - unless switched off, a mail to the organization's owners and administrators, in the
 *    environment's language — they are the ones who can ask their IdP for a new certificate,
 *    or be sent an Admin Portal link to upload it.
 *
 * Already expired counts as the tightest threshold: a connection that lapsed between two
 * scans is the one that most needs saying.
 */
final readonly class CertificateExpiryAlerts
{
    public function __construct(
        private ConnectionCertificates $certificates,
        private EnvironmentContext $environments,
        private EventBus $events,
        private EnterpriseAudit $audit,
        private Memberships $memberships,
        private Subjects $subjects,
    ) {}

    /**
     * Scan every active environment. Returns how many alerts went out.
     */
    public function run(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $sent = 0;

        foreach (Environment::query()->where('status', EnvironmentStatus::Active)->cursor() as $environment) {
            $sent += (int) $this->environments->runAs($environment, fn (): int => $this->scanEnvironment($now));
        }

        return $sent;
    }

    /**
     * The warnings an organization's console pages show: each active SAML connection of
     * $organizationId that stops working within the widest threshold, soonest first.
     *
     * @return list<array{connection_id: string, name: string, expires_at: string, days_remaining: int, expired: bool}>
     */
    public function warningsFor(string $organizationId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $horizon = max(self::thresholds());
        $warnings = [];

        foreach ($this->samlConnections()->where('organization_id', $organizationId)->get() as $connection) {
            $certificate = $this->certificates->effectiveExpiry($connection);

            if ($certificate === null || $certificate->daysRemaining($now) > $horizon) {
                continue;
            }

            $warnings[] = [
                'connection_id' => $connection->id,
                'name' => $connection->name,
                'expires_at' => $certificate->notAfter->toIso8601ZuluString(),
                'days_remaining' => $certificate->daysRemaining($now),
                'expired' => $certificate->isExpired($now),
            ];
        }

        usort($warnings, static fn (array $a, array $b): int => $a['days_remaining'] <=> $b['days_remaining']);

        return $warnings;
    }

    /**
     * The thresholds, in days, tightest first.
     *
     * @return non-empty-list<int>
     */
    public static function thresholds(): array
    {
        $configured = config('cbox-id.portal.certificate_alerts.thresholds', [30, 7]);
        $days = is_array($configured)
            ? array_values(array_filter($configured, static fn (mixed $day): bool => is_int($day) && $day > 0))
            : [];

        sort($days);

        return $days === [] ? [7, 30] : $days;
    }

    private function scanEnvironment(CarbonImmutable $now): int
    {
        $sent = 0;

        foreach ($this->samlConnections()->cursor() as $connection) {
            $certificate = $this->certificates->effectiveExpiry($connection);

            if ($certificate === null) {
                continue;
            }

            $days = $certificate->daysRemaining($now);
            $threshold = null;

            foreach (self::thresholds() as $candidate) {
                if ($days <= $candidate) {
                    $threshold = $candidate;

                    break;
                }
            }

            if ($threshold === null || $this->alreadySaid($connection, $certificate, $threshold)) {
                continue;
            }

            $this->alert($connection, $certificate, $threshold, $days, $now);
            $sent++;
        }

        return $sent;
    }

    /**
     * Said at this threshold already — or at a tighter one, which a looser one would only
     * repeat less urgently.
     */
    private function alreadySaid(Connection $connection, SamlCertificate $certificate, int $threshold): bool
    {
        return CertificateAlert::query()
            ->where('connection_id', $connection->id)
            ->where('fingerprint', $certificate->fingerprint)
            ->where('threshold_days', '<=', $threshold)
            ->exists();
    }

    private function alert(Connection $connection, SamlCertificate $certificate, int $threshold, int $days, CarbonImmutable $now): void
    {
        $payload = [
            'id' => $connection->id,
            'name' => $connection->name,
            'type' => $connection->type->value,
            'expires_at' => $certificate->notAfter->toIso8601ZuluString(),
            'days_remaining' => $days,
            'threshold_days' => $threshold,
            'certificate' => [
                'fingerprint_sha256' => $certificate->fingerprint,
                'subject' => $certificate->subject,
                'not_after' => $certificate->notAfter->toIso8601ZuluString(),
            ],
        ];

        CertificateAlert::query()->create([
            'organization_id' => $connection->organization_id,
            'connection_id' => $connection->id,
            'fingerprint' => $certificate->fingerprint,
            'threshold_days' => $threshold,
            'not_after' => $certificate->notAfter,
            'notified_at' => $now,
        ]);

        $this->events->emit(new DomainEvent(EnterpriseAudit::SSO_CERTIFICATE_EXPIRING, $payload, $connection->organization_id));

        $this->audit->record(EnterpriseAudit::SSO_CERTIFICATE_EXPIRING, new AuditActor(ActorType::System), $connection->organization_id, 'connection', $connection->id, [
            'name' => $connection->name,
            'fingerprint' => $certificate->fingerprint,
            'expires_at' => $payload['expires_at'],
            'threshold_days' => $threshold,
        ]);

        if ($connection->organization_id !== null && config('cbox-id.portal.certificate_alerts.mail_admins', true) === true) {
            $this->mailAdministrators($connection, $connection->organization_id, $certificate, $days);
        }
    }

    /**
     * The organization's active owners and administrators, each in the environment's
     * default language — the scan runs with no visitor to ask.
     */
    private function mailAdministrators(Connection $connection, string $organizationId, SamlCertificate $certificate, int $days): void
    {
        $administrators = $this->memberships->forOrganization($organizationId)
            ->filter(static fn (Membership $membership): bool => $membership->status === MembershipStatus::Active
                && in_array($membership->role, [MembershipRole::Owner, MembershipRole::Admin], true))
            ->pluck('user_id')
            ->all();

        if ($administrators === []) {
            return;
        }

        $organization = Organization::query()->whereKey($organizationId)->value('name');
        // A fresh instance: the scan crosses environments, and the service memoises one.
        $locale = (new HostedLocales($this->environments))->default()->value;

        foreach ($this->subjects->findMany(array_values(array_filter($administrators, 'is_string'))) as $subject) {
            $email = $subject->email;

            if (! is_string($email) || $email === '') {
                continue;
            }

            Mail::to($email)->locale($locale)->send(new CertificateExpiringMail(
                organization: is_string($organization) ? $organization : '',
                connectionName: $connection->name,
                expiresAt: $certificate->notAfter,
                daysRemaining: $days,
            ));
        }
    }

    /**
     * @return Builder<Connection>
     */
    private function samlConnections(): Builder
    {
        return Connection::query()
            ->where('type', ConnectionType::Saml)
            ->where('status', ConnectionStatus::Active);
    }
}
