<?php

declare(strict_types=1);

namespace App\Platform\Realm;

/**
 * WHAT THE REALM FLIP DOES WITH EVERY TABLE, AND WHY.
 *
 * One entry per table that stores an environment id, an organization id or a person's id.
 * {@see RealmFlip} acts on this list, prints it as its plan, and does not trust it: a
 * table that holds rows of the old root and is missing here blocks the flip, and after
 * the last step every text column of every table is searched for a retired id.
 *
 * The decisions:
 *
 * - MOVE: the old root's rows go into the realm, re-keyed to the surviving organization
 *   and person. Where the realm already has a row for the same thing, the realm's wins
 *   and the root's is not carried (the plan says how many).
 * - FOLLOW: a person's own rows (credentials, devices). They move with a person whose
 *   account moved into the realm, and are dropped for a person who already has a realm
 *   account, because that account, with its own credentials, is the one that survives.
 * - DROP: sessions, tokens, challenges, counters and derived data of the old root.
 * - ARCHIVE: copied verbatim to `realm_flip_archive`, then removed from the live table.
 * - REPOINT: a table with no environment of its own; its id columns are re-keyed.
 * - KEEP: holds nothing of the old root; listed so that the list is complete.
 *
 * Under every decision, the columns named in `refs` are re-keyed IN EVERY ROW OF EVERY
 * ENVIRONMENT: a tenant environment's rows name the console's people too (who created a
 * key, who sent an invitation).
 *
 * @phpstan-type Entry array{decision: string, reason: string, refs: array<string, mixed>}
 */
final class FlipTables
{
    public const MOVE = 'move';

    public const FOLLOW = 'follow';

    public const DROP = 'drop';

    public const ARCHIVE = 'archive';

    public const REPOINT = 'repoint';

    public const KEEP = 'keep';

    /** Carried by their own steps in {@see RealmFlip}, not row by row from this list. */
    public const BY_HAND = ['organizations', 'users', 'memberships'];

    /** The tamper-evident trail: left in place under --keep-root-audit. */
    public const AUDIT_TRAIL = ['audit_logs', 'audit_checkpoints'];

    /**
     * History. A row here may go on naming a retired id, because rewriting it would
     * falsify what happened (a hash chain, a delivered payload, a log line).
     */
    public const HISTORY = [
        'audit_logs', 'audit_checkpoints', 'app_audit_events', 'app_audit_event_targets', 'app_audit_chains', 'app_audit_exports',
        'events', 'id_analytics_events', 'webhook_deliveries', 'stream_deliveries', 'provisioning_operations',
        'failed_jobs', 'risk_decisions', 'risk_plus_events', 'postal_messages', 'postal_message_events',
    ];

    /** Not searched for retired ids: no ids, or emptied by the flip. */
    public const UNSEARCHED = ['cache', 'cache_locks', 'migrations', 'job_batches', 'queue_monitor_jobs', 'queue_monitor_tags', 'queue_monitor_cluster_events', 'queue_monitor_scaling_events'];

    /** Environment settings that only made sense on the old root. */
    public const ROOT_ONLY_SETTINGS = ['sign_in_through', 'custom_domain'];

    /** Config keys that name an environment by id or slug, and the variable behind each. */
    public const ENV_VARS = [
        'cbox-id.environments.default' => 'CBOX_ID_ENVIRONMENT_DEFAULT',
        'cbox-id.cbox_environment' => 'CBOX_ID_CBOX_ENVIRONMENT',
        'service-access.identity_environment' => 'CBOX_WAITLISTS_ID_ENVIRONMENT',
        'signals-data.environments' => 'SIGNALS_DATA_ENVIRONMENTS',
        'signals-auth.id_environment' => 'SIGNALS_AUTH_ID_ENVIRONMENT',
        'id-devices.app_review.environment_id' => 'CBOX_ID_APP_REVIEW_ENVIRONMENT_ID (with _SUBJECT_ID and _CLIENT_ID)',
        'ai-assistant-connector.support.identity_namespace' => 'AI_ASSISTANT_IDENTITY_NAMESPACE',
    ];

    /**
     * Sessions and tokens, in every environment: a column to stamp, or `delete` for a row
     * that is only a pending step of a flow. Children before parents.
     */
    public const REVOKE = [
        'auth_sessions' => 'revoked_at',
        'oauth_access_tokens' => 'revoked_at',
        'oauth_refresh_tokens' => 'revoked_at',
        'oauth_session_participants' => 'ended_at',
        'support_sessions' => 'ended_at',
        'id_device_approval_evidence' => 'delete',
        'oauth_authorization_codes' => 'delete',
        'device_codes' => 'delete',
        'ciba_requests' => 'delete',
        'pushed_authorization_requests' => 'delete',
        'frontend_login_tickets' => 'delete',
        'saml_idp_sessions' => 'delete',
        'saml_auth_requests' => 'delete',
        'magic_links' => 'delete',
        'otp_challenges' => 'delete',
        'action_approval_requests' => 'delete',
        'action_idempotency_records' => 'delete',
        'session_lineage' => 'delete',
        'sessions' => 'delete',
    ];

    /** Rows of a table with no environment column whose parent went with the old root. */
    public const ORPHANS = [
        'role_permission' => ['role_id' => ['roles', 'id'], 'permission_id' => ['permissions', 'id']],
        'directory_group_members' => ['directory_user_id' => ['directory_users', 'id']],
        'frontend_api_origins' => ['frontend_api_key_id' => ['frontend_api_keys', 'id']],
        'oauth_api_scopes' => ['api_id' => ['oauth_apis', 'id']],
    ];

    /** @return array<string, Entry> in the order the flip works through them (parents first) */
    public static function all(): array
    {
        $o = ['organization' => ['organization_id']];
        $u = ['user' => ['user_id']];
        $ou = [...$o, ...$u];
        $client = ['parent' => [['oauth_clients', 'client_id', 'client_id']]];

        return [
            // ── The realm itself ────────────────────────────────────────────────────
            'organizations' => self::move('A linked organization is already in the realm: its root row is dropped and everything that named it is re-keyed. An unlinked one moves in with its id under --create-missing.', ['organization' => ['parent_id']]),
            'organization_closure' => self::move('The organization tree. A moved organization takes its rows; a linked one has its own in the realm.', ['organization' => ['ancestor_id', 'descendant_id']]),
            'users' => self::move('A person with a realm account (linked sub, else same address) keeps that account and its `sub`; the root row is dropped. A person without one moves in with their id under --create-missing.'),
            'memberships' => self::move('Root team roles become realm memberships: the root row moves where the person is not a member yet, and merges into the realm row (role by --role-conflict, environment grants from the root) where they are.', [...$ou, 'user' => ['user_id', 'invited_by']]),
            'membership_environments' => self::repoint('Which tenant environments a restricted member may reach: follows the membership to its realm row. Grants to the realm itself are removed, it is no longer a tenant.', ['membership' => ['membership_id']]),
            'membership_directory_revisions' => self::repoint('The directory revision counter of an organization, emitted in events: the realm organization takes the higher of the two, plus one for the merge.', ['organization' => ['organization_id']]),
            'organization_links' => self::drop('The map between the two realms. It is what this flip reads, and it has nothing left to say afterwards: emptied.'),
            'environments' => self::keep('The old root row is deleted; the realm leaves its project, takes the root\'s place and becomes the default. Every other environment is untouched.'),
            'projects' => self::repoint('Owned by a root organization: re-pointed to the surviving organization. This is what keeps every tenant environment served.', [...$o, 'conflict' => 'fail']),
            'platform_operators' => self::repoint('Operators are people of the root: each record is re-pointed to the person\'s realm account. The record, its status and its address stay.', ['user' => ['subject_id']]),
            'operator_mfa_factors' => self::keep('Keyed by the operator record, not by a person; unused since operators became subjects.'),
            'operator_mfa_recovery_codes' => self::keep('As operator_mfa_factors.'),

            // ── Keys and clients ────────────────────────────────────────────────────
            'organization_api_keys' => self::repoint('Organization keys keep working: same token, re-pointed to the surviving organization and to the person who made them.', [...$o, 'user' => ['created_by_id'], 'conflict' => 'fail']),
            'environment_api_keys' => self::move('The old root\'s management keys now manage the realm, which is the root. In every environment, the console person who made a key is re-pointed.', ['user' => ['created_by_id']]),
            'oauth_clients' => self::move('Platform-owned and organization clients move with their client id and secrets, so shipped software keeps working. One the realm already has BY NAME is not carried (the lookup is by name).', [...$o, 'conflict' => 'fail']),
            'oauth_client_secrets' => self::move('Follow their client.', ['parent' => [['oauth_clients', 'id', 'oauth_client_id']]]),
            'native_product_clients' => self::move('The console\'s own product registration must name the root environment: re-pointed to the realm, with its client.', $client),
            'app_manifests' => self::move('The synced role and permission manifest of a client: follows the client.', $client),
            'roles' => self::move('The console\'s own roles (the native product) and any organization role: move with their client; organization roles are re-keyed.', [...$o, ...$client]),
            'permissions' => self::move('As roles.', [...$o, ...$client]),
            'role_permission' => self::keep('Role to permission pairs, by id: untouched, minus pairs whose role was not carried.'),
            'role_assignments' => self::move('Who holds which console role in an organization: re-keyed to the realm organization and person. This is how a member keeps exactly the console access they had.', $ou),
            'environment_role_assignments' => self::move('A staff role held by a person of that environment: the old root\'s are re-keyed to the person\'s realm account.', $u),
            'role_grant_subject_locks' => self::drop('A lock anchor per person, re-made on demand.'),
            'oauth_apis' => self::move('APIs registered on the old root move with their scopes.', $o),
            'oauth_api_scopes' => self::keep('Follow their API by id.'),
            'oauth_service_accounts' => self::move('Service accounts move with their client.', [...$o, ...$client]),
            'frontend_api_keys' => self::move('Publishable keys of the old root now belong to the realm.'),
            'frontend_api_origins' => self::keep('Follow their key by id.'),
            'user_api_tokens' => self::move('Personal access tokens are credentials a machine holds: carried, re-keyed to the realm account.', $ou),
            'service_connections' => self::move('An organization\'s grant for one app to call a Cbox service: re-keyed.', [...$o, 'user' => ['created_by']]),
            'app_catalog_entries' => self::move('App-switcher tiles. The realm\'s tile of the same key wins; ids listed inside a tile are re-keyed in every environment.', ['inside' => ['organization_ids', 'user_ids']]),
            'legacy_login_declarations' => self::move('Carried; the approver is re-pointed.', ['user' => ['approved_by'], ...$client]),

            // ── Invitations and the team ────────────────────────────────────────────
            'invitations' => self::move('Pending invitations into a root organization become invitations into the realm organization; the link in the mail still works. The inviter is re-pointed in every environment.', [...$o, 'user' => ['invited_by', 'accepted_by']]),
            'invitation_role_grants' => self::move('Console roles parked on a pending invitation: follow it.', $o),
            'invitation_contexts' => self::move('Where an invitation returns the person to: follows it.', $o),
            'admin_portal_links' => self::move('Setup links for a customer\'s IT admin: re-keyed, and the console person who minted or revoked one is re-pointed in every environment.', [...$o, 'user' => ['created_by', 'revoked_by']]),
            'onboarding_dismissals' => self::move('Which setup checklist somebody dismissed: re-keyed; cosmetic.', [...$o, 'user' => ['subject_id']]),
            'user_groups' => self::move('Groups of an organization: re-keyed.', $o),
            'relationship_tuples' => self::move('Group membership and resource access tuples: re-keyed (a subject is a bare id next to its type).', [...$o, 'user' => ['subject_id']]),
            'group_role_mappings' => self::move('Directory group to role: re-keyed.', $o),

            // ── Sign-in configuration ───────────────────────────────────────────────
            'connections' => self::move('An organization\'s SSO connections move to the realm organization (the "team sign-in connection"). Environment-wide providers move only where the realm has none of that provider; the connection the old root signed in THROUGH is retired.', [...$o, 'one' => ['type', 'provider']]),
            'identities' => self::follow('A person\'s linked external identities. The link the old root used to sign in through the realm is retired with its connection.', [...$u, 'parent' => [['connections', 'id', 'connection_id']]]),
            'sign_in_provider_opt_outs' => self::move('An organization\'s opt-out of an environment-wide provider: re-keyed.', $o),
            'auth_policies' => self::move('Sign-in policy. An organization\'s policy (its SSO mandate) moves to the realm organization; the environment baseline moves only if the realm has none.', [...$o, 'one' => []]),
            'sms_factor_policies' => self::move('Carried where the realm has none.', ['one' => []]),
            'verified_domains' => self::move('An organization\'s verified domains: re-keyed. A domain the realm already has stays with the realm\'s row.', $o),
            'whitelabel_brand_profiles' => self::move('Branding: an organization\'s moves with it; the environment default moves only if the realm has none.', [...$o, 'one' => [], 'inside' => ['logo_url', 'favicon_url']]),
            'whitelabel_brand_assets' => self::move('Uploaded logos, stored under a path that names the environment: moved, path and all.', ['inside' => ['path']]),
            'saml_service_providers' => self::move('Service providers the old root was an IdP for: re-keyed.', $o),
            'saml_idp_certificates' => self::drop('The old root\'s IdP certificate. The realm signs with its own.'),
            'saml_idp_entity_ids' => self::drop('The old root\'s frozen IdP EntityID names a host that no longer issues.'),
            'saml_idp_name_ids' => self::drop('Per-service-provider pseudonyms minted by the old root for its people.'),
            'signing_keys' => self::drop('The old root\'s token-signing keys. Everything they signed is revoked; the realm keeps its own keys and its `kid`s.'),
            'radar_settings' => self::move('Carried where the realm has none.', ['one' => []]),
            'radar_rules' => self::move('Carried.'),
            'radar_list_entries' => self::move('Carried.'),
            'radar_devices' => self::drop('Browsers a person signed in from, stored as an HMAC of their old id: rebuilt as they sign in.'),
            'feature_flags' => self::move('Carried; the realm\'s flag of the same key wins.'),
            'feature_flag_targets' => self::move('Per-person and per-organization flag rules: follow their flag, re-keyed.', ['inside' => ['target_id'], 'parent' => [['feature_flags', 'id', 'feature_flag_id']]]),
            'entitlements' => self::move('Entitlement rows of a root organization (no live writer; Billing is the source): re-keyed, the realm\'s row wins.', $o),
            'entitlement_history' => self::move('History of the above: re-keyed.', $o),
            'usage_counters' => self::move('Per-day usage counts of an organization: re-keyed; a day the realm organization already counted keeps the realm\'s number.', $o),
            'product_billing_usage_outbox' => self::repoint('Usage waiting to be reported to Billing, keyed by the owning organization: re-keyed, so it is reported against the surviving id.', ['organization' => ['workspace_id']]),
            'usage_metered_events' => self::keep('A dedupe marker by event id.'),

            // ── A person's own rows ─────────────────────────────────────────────────
            'webauthn_credentials' => self::follow('Passkeys. A person with a realm account keeps the passkeys of THAT account: a passkey carries the user id it was made for, so the root\'s cannot answer for another account.'),
            'mfa_factors' => self::follow('Second factors, sealed to the user id they belong to: they cannot be re-keyed to another account.'),
            'mfa_recovery_codes' => self::follow('As mfa_factors.'),
            'password_ages' => self::follow('Password age, with the password.'),
            'password_history' => self::follow('Password history, with the password.'),
            'password_change_requirements' => self::follow('A forced password change, with the password.', ['user' => ['user_id', 'set_by_id']]),
            'id_devices' => self::follow('Enrolled Authenticator handsets, bound to the old root\'s issuer and user id.', ['user' => ['subject_id']]),
            'user_property_documents' => self::follow('Per-person property documents.'),
            'pipe_connections' => self::follow('A person\'s connected third-party account.'),
            'directory_users' => self::move('Mirrored directory users: follow their directory; the linked person is re-pointed.', $u),

            // ── Organization features ───────────────────────────────────────────────
            'directories' => self::move('SCIM and HRIS directories of an organization: re-keyed; the bearer token keeps working.', $o),
            'directory_groups' => self::move('Follow their directory.'),
            'directory_group_members' => self::keep('Follow their directory user by id.'),
            'provisioning_connections' => self::move('Outbound SCIM connections: re-keyed.', $o),
            'provisioned_resources' => self::move('What outbound SCIM created where: re-keyed.', $u),
            'provisioning_operations' => self::drop('The outbound SCIM outbox of the old root.'),
            'webhook_endpoints' => self::move('Webhook receivers: re-keyed, secret intact.', $o),
            'webhook_deliveries' => self::drop('Delivery history of the old root\'s endpoints.'),
            'external_action_endpoints' => self::move('Inline hook endpoints: re-keyed.', $o),
            'log_streams' => self::move('SIEM streams: re-keyed.', $o),
            'stream_deliveries' => self::drop('The SIEM outbox of the old root.'),
            'vault_secrets' => self::move('Sealed third-party credentials, owned by an organization, a person or the environment: re-keyed (the seal is bound to the row, not to the owner).', ['inside' => ['owner_id']]),
            'vault_grants' => self::move('Which client may lease a secret: follows it.'),
            'pipes' => self::move('The environment\'s OAuth apps at third parties: carried.'),
            'pipe_grants' => self::move('Follow their pipe.'),
            'user_property_namespaces' => self::move('Carried.'),
            'fga_stores' => self::move('Carried.'),
            'fga_tuples' => self::move('Carried; a subject id that is a person\'s is re-keyed.', ['user' => ['subject_id']]),
            'governance_campaigns' => self::move('Access reviews: re-keyed.', $o),
            'governance_certification_items' => self::move('Access review items: re-keyed.', [...$o, 'user' => ['subject_id', 'decided_by']]),
            'governance_sod_policies' => self::move('Segregation-of-duties policies: re-keyed.', $o),
            'sso_certificate_alerts' => self::drop('Which expiry alerts were already sent; sent again at worst.'),
            'app_audit_settings' => self::move('Carried where the realm has none.', ['one' => []]),
            'app_audit_schemas' => self::move('Carried.'),

            // ── Audit ───────────────────────────────────────────────────────────────
            'audit_logs' => self::archive('Hash-chained per environment and scope: an entry\'s hash covers its environment id, organization id and sequence, so the old root\'s trail cannot join the realm\'s chain. Verified, archived verbatim, removed. The realm\'s chains are untouched.'),
            'audit_checkpoints' => self::archive('Signed checkpoints over the old root\'s chains: archived with them.'),
            'app_audit_chains' => self::archive('Customer-app audit chains of the old root: archived, not re-chained.'),
            'app_audit_events' => self::archive('As app_audit_chains.'),
            'app_audit_event_targets' => self::archive('As app_audit_chains.'),
            'app_audit_exports' => self::drop('Export jobs and their expired files.'),
            'audit_export_cursors' => self::drop('The compliance export position per chain: the old root\'s chains are gone, the realm\'s cursors stand.'),
            'audit_export_runs' => self::drop('Export run bookkeeping.'),

            // ── Sessions, tokens and other short-lived state ────────────────────────
            'auth_sessions' => self::drop('Sessions. Every session in every environment is revoked.'),
            'sessions' => self::drop('Console sessions: all deleted.'),
            'session_lineage' => self::drop('Which console session came from which: all deleted with them.'),
            'oauth_access_tokens' => self::drop('Tokens. Every token in every environment is revoked.'),
            'oauth_refresh_tokens' => self::drop('As oauth_access_tokens.'),
            'oauth_authorization_codes' => self::drop('Pending codes: deleted everywhere.'),
            'oauth_session_participants' => self::drop('Which clients took part in a session: ended everywhere.'),
            'device_codes' => self::drop('Pending device grants: deleted everywhere.'),
            'ciba_requests' => self::drop('Pending approvals and CIBA requests: deleted everywhere.'),
            'pushed_authorization_requests' => self::drop('Pending PAR: deleted everywhere.'),
            'frontend_login_tickets' => self::drop('60-second sign-in tickets.'),
            'saml_idp_sessions' => self::drop('IdP sessions: deleted everywhere.'),
            'saml_auth_requests' => self::drop('In-flight SAML requests.'),
            'support_sessions' => self::drop('Support sessions: ended everywhere; the console actor is re-pointed.', ['user' => ['actor_id', 'ended_by']]),
            'action_approval_requests' => self::drop('Pending action approvals name the person inside a hashed principal that cannot be re-keyed: deleted everywhere; raise them again.'),
            'action_idempotency_records' => self::drop('Day-long replay records, keyed the same way: deleted everywhere.'),
            'magic_links' => self::drop('Pending sign-in links.'),
            'otp_challenges' => self::drop('Pending one-time codes.'),
            'consumed_assertions' => self::drop('Replay guard.'),
            'dpop_proofs' => self::drop('Replay guard.'),
            'cbox_id_password_reset_tokens' => self::drop('Pending password resets of the old root.'),
            'email_verification_tokens' => self::drop('Pending address confirmations of the old root.'),
            'login_attempt_counters' => self::drop('Lockout counters.'),
            'id_enrolment_codes' => self::drop('Spent device-enrolment codes.'),
            'id_device_notifications' => self::drop('Push attempts.'),
            'id_device_approval_evidence' => self::drop('Which handset answered a request; goes with the request.'),
            'id_approval_reports' => self::drop('"This was not me" reports against requests that are gone.'),
            'risk_decisions' => self::drop('Pre-sign-in risk verdicts (telemetry).'),
            'risk_plus_events' => self::drop('Flagged sign-in feed (telemetry).'),

            // ── Derived and outbound ────────────────────────────────────────────────
            'events' => self::drop('The domain-event outbox of the old root. Delivered events are history; an undelivered one names ids that no longer exist.'),
            'id_analytics_events' => self::drop('Analytics facts derived from the old root\'s events.'),
            'signals_source_heads' => self::drop('The Signals data ledger of the old root, keyed by hashes of the old ids: Signals reads the realm from its own ledger.'),
            'signals_source_records' => self::drop('As signals_source_heads.'),
            'signals_source_pending' => self::drop('As signals_source_heads.'),
            'signals_source_subjects' => self::drop('As signals_source_heads.'),
            'signals_source_memberships' => self::drop('As signals_source_heads.'),
            'signals_source_erasures' => self::drop('As signals_source_heads.'),
            'signals_source_erasure_positions' => self::drop('As signals_source_heads.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $refs
     * @return Entry
     */
    private static function move(string $reason, array $refs = []): array
    {
        return ['decision' => self::MOVE, 'reason' => $reason, 'refs' => $refs];
    }

    /**
     * @param  array<string, mixed>  $refs
     * @return Entry
     */
    private static function follow(string $reason, array $refs = ['user' => ['user_id']]): array
    {
        return ['decision' => self::FOLLOW, 'reason' => $reason, 'refs' => $refs];
    }

    /**
     * @param  array<string, mixed>  $refs
     * @return Entry
     */
    private static function drop(string $reason, array $refs = []): array
    {
        return ['decision' => self::DROP, 'reason' => $reason, 'refs' => $refs];
    }

    /** @return Entry */
    private static function archive(string $reason): array
    {
        return ['decision' => self::ARCHIVE, 'reason' => $reason, 'refs' => []];
    }

    /**
     * @param  array<string, mixed>  $refs
     * @return Entry
     */
    private static function repoint(string $reason, array $refs): array
    {
        return ['decision' => self::REPOINT, 'reason' => $reason, 'refs' => $refs];
    }

    /** @return Entry */
    private static function keep(string $reason): array
    {
        return ['decision' => self::KEEP, 'reason' => $reason, 'refs' => []];
    }
}
