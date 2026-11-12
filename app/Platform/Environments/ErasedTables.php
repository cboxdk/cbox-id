<?php

declare(strict_types=1);

namespace App\Platform\Environments;

use App\Platform\Realm\FlipTables;

/**
 * WHAT DELETING AN ENVIRONMENT DOES WITH EVERY TABLE THAT NAMES ONE.
 *
 * One entry per table with an `environment_id` (or `environment_key`) column, the same
 * idea as the realm flip's {@see FlipTables}: {@see EnvironmentErasure} acts on this list
 * and does not trust it. A table that holds rows of the environment and is missing here
 * refuses the deletion, and a test fails the build the day a migration adds one.
 *
 * Two decisions:
 *
 * - ERASE: the environment's rows are deleted. This is nearly everything, because an
 *   environment IS its people, apps, keys, sessions and settings.
 * - KEEP: the platform's tamper-evident trail. Each chain is keyed by (environment, scope)
 *   and is append-only by design (a deployment may run it under a trigger that refuses
 *   DELETE for every role), so the deleted environment's chains stay where they are and go
 *   on verifying. Nothing reads them from the console: no host resolves to the environment
 *   any more.
 *
 * @phpstan-type Entry array{decision: string, reason: string}
 */
final class ErasedTables
{
    public const ERASE = 'erase';

    public const KEEP = 'keep';

    private const PEOPLE = [
        'users', 'identities', 'webauthn_credentials', 'mfa_factors', 'mfa_recovery_codes', 'password_ages', 'password_history',
        'password_change_requirements', 'id_devices', 'id_enrolment_codes', 'id_device_notifications', 'id_device_approval_evidence',
        'id_approval_reports', 'user_property_documents', 'user_property_namespaces', 'pipe_connections', 'user_api_tokens',
        'onboarding_dismissals',
    ];

    private const ORGANIZATIONS = [
        'organizations', 'organization_closure', 'memberships', 'membership_environments', 'invitations', 'invitation_role_grants',
        'invitation_contexts', 'admin_portal_links', 'user_groups', 'relationship_tuples', 'group_role_mappings', 'verified_domains',
        'sign_in_provider_opt_outs',
    ];

    private const APPS = [
        'oauth_clients', 'oauth_client_secrets', 'native_product_clients', 'app_manifests', 'oauth_apis', 'oauth_api_scopes',
        'oauth_service_accounts', 'frontend_api_keys', 'environment_api_keys', 'service_connections', 'app_catalog_entries',
        'legacy_login_declarations', 'saml_service_providers', 'roles', 'permissions', 'role_assignments',
        'environment_role_assignments', 'role_grant_subject_locks',
    ];

    private const SESSIONS = [
        'auth_sessions', 'oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_authorization_codes', 'oauth_session_participants',
        'device_codes', 'ciba_requests', 'pushed_authorization_requests', 'frontend_login_tickets', 'saml_idp_sessions',
        'saml_auth_requests', 'support_sessions', 'action_approval_requests', 'magic_links', 'otp_challenges', 'consumed_assertions',
        'dpop_proofs', 'cbox_id_password_reset_tokens', 'email_verification_tokens', 'login_attempt_counters',
    ];

    private const SIGN_IN = [
        'connections', 'auth_policies', 'sms_factor_policies', 'whitelabel_brand_profiles', 'whitelabel_brand_assets',
        'saml_idp_certificates', 'saml_idp_entity_ids', 'saml_idp_name_ids', 'signing_keys', 'sso_certificate_alerts', 'radar_settings',
        'radar_rules', 'radar_list_entries', 'radar_devices', 'risk_decisions', 'risk_plus_events', 'feature_flags',
        'feature_flag_targets',
    ];

    private const INTEGRATIONS = [
        'directories', 'directory_groups', 'directory_users', 'provisioning_connections', 'provisioned_resources',
        'provisioning_operations', 'webhook_endpoints', 'webhook_deliveries', 'external_action_endpoints', 'log_streams',
        'stream_deliveries', 'vault_secrets', 'vault_grants', 'pipes', 'pipe_grants', 'fga_stores', 'fga_tuples', 'governance_campaigns',
        'governance_certification_items', 'governance_sod_policies',
    ];

    private const APP_AUDIT = [
        'app_audit_settings', 'app_audit_schemas', 'app_audit_chains', 'app_audit_events', 'app_audit_event_targets', 'app_audit_exports',
    ];

    private const DERIVED = [
        'events', 'id_analytics_events', 'entitlements', 'entitlement_history', 'usage_counters', 'audit_export_cursors',
        'audit_export_runs', 'signals_source_heads', 'signals_source_records', 'signals_source_pending', 'signals_source_subjects',
        'signals_source_memberships', 'signals_source_erasures', 'signals_source_erasure_positions',
    ];

    /** The platform's own hash-chained trail and its signed checkpoints. */
    private const TRAIL = ['audit_logs', 'audit_checkpoints'];

    /**
     * Rows of a table with no environment column that belong to a row which has one:
     * child column => [parent table, parent column]. Deleted BEFORE their parents, by the
     * parent's environment. (A foreign key cascades some of them already; this does not
     * depend on it.)
     */
    public const CHILDREN = [
        'role_permission' => ['role_id' => ['roles', 'id'], 'permission_id' => ['permissions', 'id']],
        'directory_group_members' => ['directory_user_id' => ['directory_users', 'id']],
        'frontend_api_origins' => ['frontend_api_key_id' => ['frontend_api_keys', 'id']],
    ];

    /** @return array<string, Entry> */
    public static function all(): array
    {
        $groups = [
            'People, their credentials, devices and personal data.' => self::PEOPLE,
            'The environment\'s organizations, their members, invitations, groups and domains. A grant that let an organization member of the console reach this environment goes with it.' => self::ORGANIZATIONS,
            'Apps and clients, their secrets, APIs, roles and permissions, and every key issued for the environment.' => self::APPS,
            'Sessions, tokens and pending steps of a sign-in or an approval.' => self::SESSIONS,
            'How people sign in: connections, policies, branding, signing keys and certificates, risk rules, feature flags.' => self::SIGN_IN,
            'Directories, provisioning, webhooks, hooks, log streams, vault secrets, pipes, FGA stores and access reviews.' => self::INTEGRATIONS,
            'The audit log the customer\'s own apps write through the Audit Logs API. It is their data, not the platform\'s trail.' => self::APP_AUDIT,
            'Outboxes, counters and ledgers derived from the rows above.' => self::DERIVED,
        ];

        $tables = [];

        foreach ($groups as $reason => $names) {
            foreach ($names as $table) {
                $tables[$table] = ['decision' => self::ERASE, 'reason' => $reason];
            }
        }

        foreach (self::TRAIL as $table) {
            $tables[$table] = ['decision' => self::KEEP, 'reason' => 'The platform\'s tamper-evident trail: append-only, kept in place, and still verified by `audit-chain:verify`.'];
        }

        return $tables;
    }

    /** @return list<string> */
    public static function kept(): array
    {
        return self::TRAIL;
    }
}
