<?php

declare(strict_types=1);

namespace App\Platform\Enums;

use App\Platform\Actions\Principal\PortalPrincipal;
use App\Platform\Entitlements;

/**
 * ONE THING an Admin Portal link lets a customer's IT administrator set up — the way
 * WorkOS calls them "intents". A link carries a SET of them ({@see PortalScope}), chosen by
 * whoever minted it, and the portal draws one guided step per intent on its home page.
 *
 * Each intent says three things, in one place so the portal, the minting action and the
 * principal cannot disagree about them:
 *
 *  - which ACTIONS it lets the portal session run ({@see actions()}) — the whole of what a
 *    portal session may do is the union of its intents' lists, and
 *    {@see PortalPrincipal::authorize()} refuses everything else;
 *  - which ENTITLEMENT the organization's plan must include for it ({@see entitlement()}),
 *    or none: proving a domain and shipping your own organization's trail to your own SIEM
 *    are not paid features;
 *  - its stable wire value, which is what the API takes, the link stores and the console
 *    posts.
 *
 * AUDIT LOGS is the one intent that sets nothing up: the organization's own audit events,
 * read-only, with a CSV of them. It lists no action — reading is the portal page's own —
 * and a link covering nothing else opens straight onto them.
 *
 * Single sign-on also covers the organization's email DOMAINS, as it always has: a
 * connection nobody's address routes to signs nobody in, so a link that may set up SSO but
 * not prove the domain would hand the IT administrator a step they cannot finish.
 */
enum PortalIntent: string
{
    case Sso = 'sso';
    case Dsync = 'dsync';
    case DomainVerification = 'domain_verification';
    case LogStreams = 'log_streams';
    case CertificateRenewal = 'certificate_renewal';
    case AuditLogs = 'audit_logs';

    /** The organization's email domains — claimed, proved and given up — shared by two intents. */
    private const array DOMAIN_ACTIONS = [
        'organizations.domains.add',
        'organizations.domains.verify',
        'organizations.domains.remove',
    ];

    /**
     * The actions a portal session holding this intent may run. Reads are not listed: the
     * portal's pages read through the session's own organization, not through actions.
     *
     * @return list<string>
     */
    public function actions(): array
    {
        return match ($this) {
            self::Sso => [
                ...self::DOMAIN_ACTIONS,
                'sso.connections.create',
                'sso.connections.update',
                'sso.connections.activate',
                'sso.saml_metadata.import',
            ],
            self::Dsync => [
                'directories.create',
                'directories.token.rotate',
                'directories.hris.connect',
                'directories.sync',
            ],
            self::DomainVerification => self::DOMAIN_ACTIONS,
            self::LogStreams => [
                'log_streams.create',
                'log_streams.test',
                'log_streams.delete',
            ],
            self::CertificateRenewal => [
                'sso.saml_metadata.import',
                'sso.connections.certificates.stage',
                'sso.connections.certificates.activate',
            ],
            self::AuditLogs => [],
        };
    }

    /**
     * The feature the organization's plan must include for this intent, as
     * {@see Entitlements} names it — or null when every organization may.
     */
    public function entitlement(): ?string
    {
        return match ($this) {
            self::Sso, self::CertificateRenewal => 'sso',
            self::Dsync => 'scim',
            self::AuditLogs => 'audit_logs',
            self::DomainVerification, self::LogStreams => null,
        };
    }

    /** What the console's "Admin Portal link" dialog calls it. The portal says it in the visitor's language. */
    public function label(): string
    {
        return match ($this) {
            self::Sso => 'Enterprise SSO',
            self::Dsync => 'Directory Sync',
            self::DomainVerification => 'Domain verification',
            self::LogStreams => 'Log streams',
            self::CertificateRenewal => 'SAML certificate renewal',
            self::AuditLogs => 'Audit logs (read-only)',
        };
    }

    /** The one-line explanation beside the label in the console's dialog. */
    public function description(): string
    {
        return match ($this) {
            self::Sso => 'Connect their identity provider (SAML or OIDC) and prove the email domains that route to it.',
            self::Dsync => 'Provision and deprovision their people over SCIM from their directory, or from their HR system.',
            self::DomainVerification => 'Prove the email domains they own with a DNS TXT record.',
            self::LogStreams => 'Stream their organization\'s audit trail to their own SIEM.',
            self::CertificateRenewal => 'Upload their identity provider\'s new SAML signing certificate before the old one expires.',
            self::AuditLogs => 'Read the audit events your app sent about their organization, and export them as CSV.',
        };
    }

    /**
     * The wire values, in display order — what the action's `intents` field accepts.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $intent): string => $intent->value, self::cases());
    }
}
