<?php

declare(strict_types=1);

namespace App\Platform\PublicIds;

use Illuminate\Support\Str;

/**
 * WHICH KIND OF RECORD A NAME HOLDS THE ID OF — a field (`user_id`), a nested object
 * (`"organization": {"id": …}`), an `object` (`"object": "member"`) or a resource in a path
 * (`/organization-memberships`).
 *
 * Deliberately a list of the names this application uses, not a guess from any name: an id
 * whose kind is not known for certain stays bare. A wrong prefix would be worse than none,
 * because the id would then be refused when it comes back.
 */
final class IdNames
{
    /**
     * `<stem>_id` and `<stem>_ids` fields, by stem. `client_id` is absent on purpose (it is the
     * OAuth `cid_` value, never prefixed), as are the ones whose kind depends on where they
     * stand (`actor_id`, `subject_id`, `target_id`, `secret_id`, `key_id`).
     *
     * @var array<string, string>
     */
    public const array FIELDS = [
        'organization' => 'organization',
        'workspace' => 'organization',
        'user' => 'user',
        'operator' => 'user',
        'membership' => 'membership',
        'invitation' => 'invitation',
        'project' => 'project',
        'environment' => 'environment',
        'role' => 'role',
        'permission' => 'permission',
        'app' => 'app',
        'api' => 'api',
        'api_scope' => 'api_scope',
        'connection' => 'sso_connection',
        'sso_connection' => 'sso_connection',
        'directory' => 'directory',
        'directory_user' => 'directory_user',
        'directory_group' => 'directory_group',
        'group' => 'directory_group',
        'domain' => 'verified_domain',
        'webhook' => 'webhook_endpoint',
        'endpoint' => 'webhook_endpoint',
        'webhook_endpoint' => 'webhook_endpoint',
        'delivery' => 'webhook_delivery',
        'event' => 'event',
        'feature_flag' => 'feature_flag',
        'flag' => 'feature_flag',
        'session' => 'session',
        'device' => 'device',
        'service_account' => 'service_account',
        'vault_secret' => 'vault_secret',
        'pipe' => 'pipe',
        'hook' => 'hook',
        'log_stream' => 'log_stream',
        'provisioning_target' => 'provisioning_target',
        'sod_policy' => 'sod_policy',
        'access_review' => 'access_review',
        'access_review_item' => 'access_review_item',
        'radar_rule' => 'radar_rule',
        'approval' => 'approval',
        'support_session' => 'support_session',
        'passkey' => 'passkey',
        'factor' => 'mfa_factor',
        'saml_app' => 'saml_app',
    ];

    /**
     * A nested object's key (singular) and the kind its `id` is.
     *
     * @var array<string, string>
     */
    public const array NESTED = [
        'organization' => 'organization',
        'user' => 'user',
        'environment' => 'environment',
        'project' => 'project',
        'role' => 'role',
        'permission' => 'permission',
        'membership' => 'membership',
        'invitation' => 'invitation',
        'app' => 'app',
        'api' => 'api',
        'directory' => 'directory',
        'webhook' => 'webhook_endpoint',
        'session' => 'session',
        'device' => 'device',
        'passkey' => 'passkey',
        'connection' => 'sso_connection',
        'domain' => 'verified_domain',
    ];

    /**
     * A `/v1` `object` — and a `/v1` path's resource, singular — and the kind its id is. A
     * sub-resource whose kind has no prefix (`secret`, `certificate`, `origin`, …) is absent.
     *
     * @var array<string, string>
     */
    public const array OBJECTS = [
        'organization' => 'organization',
        'user' => 'user',
        'operator' => 'user',
        'member' => 'membership',
        'membership' => 'membership',
        'organization_membership' => 'membership',
        'invitation' => 'invitation',
        'organization_invitation' => 'invitation',
        'project' => 'project',
        'environment' => 'environment',
        'role' => 'role',
        'environment_role' => 'role',
        'permission' => 'permission',
        'app' => 'app',
        'api' => 'api',
        'api_scope' => 'api_scope',
        'sso_connection' => 'sso_connection',
        'directory' => 'directory',
        'group' => 'directory_group',
        'organization_domain' => 'verified_domain',
        'webhook' => 'webhook_endpoint',
        'event' => 'event',
        'audit_event' => 'audit_log_event',
        'audit_log_event' => 'audit_log_event',
        'feature_flag' => 'feature_flag',
        'session' => 'session',
        'user_session' => 'session',
        'device' => 'device',
        'vault_secret' => 'vault_secret',
        'pipe' => 'pipe',
        'hook' => 'hook',
        'log_stream' => 'log_stream',
        'provisioning_target' => 'provisioning_target',
        'sod_policy' => 'sod_policy',
        'access_review' => 'access_review',
        'access_review_item' => 'access_review_item',
        'item' => 'access_review_item',
        'radar_rule' => 'radar_rule',
        'approval' => 'approval',
        'support_session' => 'support_session',
        'organization_key' => 'organization_key',
        'environment_key' => 'environment_key',
        'key' => 'environment_key',
        'api_key' => 'customer_api_key',
        'frontend_key' => 'publishable_key',
        'saml_app' => 'saml_app',
        'passkey' => 'passkey',
        'totp_factor' => 'mfa_factor',
    ];

    /** The kind a `<stem>_id` / `<stem>_ids` field holds, or null. */
    public static function field(string $name): ?string
    {
        if (preg_match('/\A([a-z_]+?)_ids?\z/', $name, $match) !== 1) {
            return null;
        }

        return self::FIELDS[$match[1]] ?? null;
    }

    /** The kind a nested object's `id` is, from the key it stands under. */
    public static function nested(string $key): ?string
    {
        return self::NESTED[Str::singular($key)] ?? null;
    }

    /** The kind an `object`'s `id` is. */
    public static function object(string $object): ?string
    {
        return self::OBJECTS[$object] ?? null;
    }

    /** The kind a path's resource segment (`organization-memberships`) names. */
    public static function resource(string $segment): ?string
    {
        return self::OBJECTS[str_replace('-', '_', Str::singular($segment))] ?? null;
    }
}
