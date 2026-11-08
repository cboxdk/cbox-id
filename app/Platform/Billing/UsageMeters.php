<?php

declare(strict_types=1);

namespace App\Platform\Billing;

/**
 * Every meter Cbox ID reports to central billing, in one place.
 *
 * All of them are fed by events. Activity (sign-ins, invitations…) is one event per
 * occurrence; monthly active users is a distinct count of the signed-in person; stock
 * (users, domains, SSO connections…) is an hourly snapshot event whose window peak is the
 * quantity. Measuring does not price anything: plans decide what is included or unlimited.
 *
 * A published meter's measurement semantics never change; a different measure is a new key.
 */
final class UsageMeters
{
    /** Domain event type → activity meter (one unit per event). */
    public const EVENTS = [
        'user.session_started' => 'sign_ins',
        'user.login' => 'sso_sign_ins',
        'user.created' => 'users_created',
        'identity.linked' => 'identity_links',
        'organization.created' => 'organizations_created',
        'organization.member_added' => 'members_added',
        'organization.member_removed' => 'members_removed',
        'organization.invitation_created' => 'invitations_sent',
        'organization.invitation_accepted' => 'invitations_accepted',
        'role.assigned' => 'role_assignments',
        'service_account.created' => 'service_accounts_created',
        'oauth.backchannel_authentication_requested' => 'ciba_requests',
        'domain.verified' => 'domains_verified',
        'governance.campaign_opened' => 'governance_campaigns',
        'directory.user.provisioned' => 'directory_provisions',
    ];

    /** Distinct people who started a session in the billing period. */
    public const ACTIVE_USERS = 'monthly_active_users';

    /** Activity counted hourly from rows created in the closed hour (no domain event exists). */
    public const HOURLY = [
        'access_tokens_issued' => 'Access tokens issued',
        'refresh_tokens_issued' => 'Refresh tokens issued',
        'otp_codes_sent' => 'One-time codes sent',
        'mfa_enrollments' => 'MFA factors enrolled',
        'passkeys_registered' => 'Passkeys registered',
    ];

    /** Stock measured by an hourly snapshot; the period's peak is the quantity. */
    public const STOCK = [
        'users' => 'Users',
        'organizations' => 'Organizations',
        'memberships' => 'Organization memberships',
        'environments' => 'Environments',
        'sso_connections' => 'SSO connections',
        'social_connections' => 'Social sign-in connections',
        'directories' => 'Directories (SCIM/HRIS)',
        'verified_domains' => 'Verified domains',
        'apps' => 'Applications',
        'api_keys' => 'Active API keys',
    ];

    private const NAMES = [
        'sign_ins' => 'Sign-ins',
        'sso_sign_ins' => 'SSO sign-ins',
        'users_created' => 'Users created',
        'identity_links' => 'Identities linked',
        'organizations_created' => 'Organizations created',
        'members_added' => 'Members added',
        'members_removed' => 'Members removed',
        'invitations_sent' => 'Invitations sent',
        'invitations_accepted' => 'Invitations accepted',
        'role_assignments' => 'Role assignments',
        'service_accounts_created' => 'Service accounts created',
        'ciba_requests' => 'Backchannel (CIBA) approvals requested',
        'domains_verified' => 'Domains verified',
        'governance_campaigns' => 'Access review campaigns opened',
        'directory_provisions' => 'Directory users provisioned',
    ];

    public static function key(string $meter): string
    {
        return 'cbox-id.'.$meter;
    }

    /**
     * The meters section of billing-definitions.json.
     *
     * @return list<array{key: string, name: string, unit: string, aggregation: string, source: string}>
     */
    public static function definitions(): array
    {
        $meters = [];
        foreach (self::EVENTS as $meter) {
            $meters[] = self::meter($meter, self::NAMES[$meter], 'events', 'sum');
        }
        $meters[] = self::meter(self::ACTIVE_USERS, 'Monthly active users', 'users', 'unique_count');
        foreach (self::HOURLY as $meter => $name) {
            $meters[] = self::meter($meter, $name, 'events', 'sum');
        }
        foreach (self::STOCK as $meter => $name) {
            $meters[] = self::meter($meter, $name, 'items', 'max');
        }

        return $meters;
    }

    /** @return array{key: string, name: string, unit: string, aggregation: string, source: string} */
    private static function meter(string $meter, string $name, string $unit, string $aggregation): array
    {
        return ['key' => self::key($meter), 'name' => $name, 'unit' => $unit, 'aggregation' => $aggregation, 'source' => 'events'];
    }
}
