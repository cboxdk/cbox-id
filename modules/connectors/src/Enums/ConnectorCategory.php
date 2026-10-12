<?php

declare(strict_types=1);

namespace Cbox\Id\Connectors\Enums;

use Cbox\Id\Connectors\Contracts\ConnectorAnalytics;

/**
 * Every kind of connection this platform makes to another system — one case per thing an
 * administrator sets up on its own page somewhere in the console.
 *
 * There used to be four, one per laravel-id module with a public "list" contract, so the
 * catalog was a list of what the plugin could COUNT rather than of what the platform could
 * CONNECT. Social login, HR systems, SAML apps, inline hooks, log streams and pipes are all
 * connections to somebody else's system, and none of them was in Connectors at all;
 * Directory Sync was there as a card that said "managed in module" and led nowhere. The set
 * is now the platform's. The values of the original four are unchanged, so an analytics
 * backend keyed on them ({@see ConnectorAnalytics}) keeps
 * working.
 */
enum ConnectorCategory: string
{
    /** Enterprise SSO: an organization's own SAML or OIDC identity provider. */
    case Federation = 'federation';

    /** Social login: Google, GitHub, Apple… offered on the sign-in page. */
    case Social = 'social';

    /** Directory Sync from an identity provider: SCIM push, or a Google Workspace / Entra pull. */
    case Directory = 'directory';

    /** Directory Sync from an HR system: Workday, BambooHR, Rippling, HiBob, Personio. */
    case Hris = 'hris';

    /** Outbound SCIM 2.0 provisioning into a downstream app. */
    case Provisioning = 'provisioning';

    /** SAML apps: service providers that trust this environment as their identity provider. */
    case SamlApp = 'saml-app';

    /** Signed event webhooks, delivered after something happened. */
    case Webhook = 'webhook';

    /** Hooks: an endpoint consulted during a sign-in or a token issuance. */
    case Hook = 'hook';

    /** Log streams: the audit log mirrored into a SIEM or a bucket. */
    case LogStream = 'log-stream';

    /** Pipes: a third-party provider people connect their own account at. */
    case Pipe = 'pipe';

    /** The short noun the Connections list prints in its Type column. */
    public function label(): string
    {
        return match ($this) {
            self::Federation => 'Enterprise SSO',
            self::Social => 'Social login',
            self::Directory => 'Directory Sync',
            self::Hris => 'HR system',
            self::Provisioning => 'Outbound provisioning',
            self::SamlApp => 'SAML app',
            self::Webhook => 'Webhook',
            self::Hook => 'Hook',
            self::LogStream => 'Log stream',
            self::Pipe => 'Pipe',
        };
    }

    /** Whether the data flows OUT to the other system (true) or IN from it (false). */
    public function isOutbound(): bool
    {
        return match ($this) {
            self::Provisioning, self::SamlApp, self::Webhook, self::Hook, self::LogStream, self::Pipe => true,
            self::Federation, self::Social, self::Directory, self::Hris => false,
        };
    }
}
