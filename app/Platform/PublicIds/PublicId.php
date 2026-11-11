<?php

declare(strict_types=1);

namespace App\Platform\PublicIds;

use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * THE PUBLIC ID OF A RECORD: `<prefix>_<ULID>` (plan §3.2, the registry in §3.3).
 *
 * The database keeps the bare ULID; the prefix is added at the boundary — the `/v1` API, the
 * console's URLs and webhook payloads an endpoint asked for in that form — and taken off again
 * wherever an id is read. Both directions live here, with the one registry of which kind of
 * record wears which prefix.
 *
 * Reading is tolerant for this round: the bare ULID is accepted wherever the prefixed one is,
 * so nothing a caller already holds stops working. A prefix of ANOTHER registered kind is not
 * tolerated: it names a different kind of record, and is answered as a record that is not
 * there ({@see decode()}), never quietly read as the id it happens to contain.
 *
 * Token claims (`sub`, `org_id`) are not touched (plan §8 Q2): every app joins its user mirror
 * on them.
 */
final class PublicId
{
    /**
     * Every kind of record with a public id, and its prefix (plan §3.3). `cid_` is the OAuth
     * `client_id` itself, a protocol value the client already carries, so it is not here: a
     * `client_id` is never prefixed and never stripped.
     *
     * @var array<string, string>
     */
    public const array PREFIXES = [
        'organization' => 'org',
        'user' => 'usr',
        'membership' => 'mbr',
        'invitation' => 'ivt',
        'project' => 'prj',
        'environment' => 'env',
        'role' => 'rol',
        'permission' => 'perm',
        'role_assignment' => 'rla',
        'app' => 'app',
        'api' => 'apir',
        'api_scope' => 'scp',
        'sso_connection' => 'conn',
        'directory' => 'dir',
        'directory_user' => 'dusr',
        'directory_group' => 'dgrp',
        'verified_domain' => 'vdom',
        'webhook_endpoint' => 'whe',
        'webhook_delivery' => 'whd',
        'event' => 'idevt',
        'audit_log_event' => 'alog',
        'feature_flag' => 'flag',
        'session' => 'ses',
        'device' => 'dvc',
        'service_account' => 'sva',
        'vault_secret' => 'vsec',
        'pipe' => 'pipe',
        'hook' => 'hook',
        'log_stream' => 'lstr',
        'provisioning_target' => 'prov',
        'sod_policy' => 'sod',
        'access_review' => 'arc',
        'access_review_item' => 'ari',
        'radar_rule' => 'rdr',
        'approval' => 'apr',
        'support_session' => 'sup',
        'app_catalog_entry' => 'capp',
        'fga_store' => 'fga',
        'organization_key' => 'okey',
        'environment_key' => 'ekey',
        'customer_api_key' => 'ckey',
        'publishable_key' => 'pkey',
        'personal_access_token' => 'pat',
        'saml_app' => 'sapp',
        'brand_profile' => 'brd',
        'mfa_factor' => 'mfa',
        'passkey' => 'psk',
    ];

    /** A ULID as Laravel stores it (lower case) or as a person may type it (upper case). */
    private const string ULID = '[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}';

    /**
     * The prefix of a kind of record.
     *
     * @throws InvalidArgumentException for a kind the registry does not hold
     */
    public static function prefix(string $type): string
    {
        return self::PREFIXES[$type] ?? throw new InvalidArgumentException("No public id prefix is registered for [{$type}].");
    }

    /**
     * The public id of a record: `encode('user', '01j…')` is `usr_01j…`.
     *
     * Only a bare ULID is prefixed. Anything else — an id that is already public, a slug, a
     * `cid_` client id, the test suite's `env_test` — comes back as it was, so encoding twice
     * or encoding a value that is not a record id is harmless.
     */
    public static function encode(string $type, string $id): string
    {
        $prefix = self::prefix($type);

        return self::isUlid($id) ? $prefix.'_'.$id : $id;
    }

    /** {@see encode()} for a value that may not be a string, or a type that may be unknown. */
    public static function encodeValue(?string $type, mixed $value): mixed
    {
        if ($type === null || ! is_string($value) || ! isset(self::PREFIXES[$type])) {
            return $value;
        }

        return self::encode($type, $value);
    }

    /**
     * The stored id a caller means, from either form: `usr_01j…` and `01j…` both read as
     * `01j…` for a user. Anything that is not an id in either form comes back unchanged, for
     * the handler to look up and not find.
     *
     * @throws NotFoundHttpException for a public id of another kind (`org_…` where a user was
     *                               asked for): a different kind of record is not there
     */
    public static function decode(string $value, string $expectedType): string
    {
        $parsed = self::parse($value);

        if ($parsed === null) {
            return $value;
        }

        [$prefix, $ulid] = $parsed;

        if ($prefix !== self::prefix($expectedType)) {
            throw new NotFoundHttpException('No such '.str_replace('_', ' ', $expectedType).'.');
        }

        return $ulid;
    }

    /** {@see decode()} for a value that may not be a string, or a type that may be unknown. */
    public static function decodeValue(?string $type, mixed $value): mixed
    {
        if ($type === null || ! is_string($value) || ! isset(self::PREFIXES[$type])) {
            return $value;
        }

        return self::decode($value, $type);
    }

    /** {@see decode()} for a kind that may be unknown (then the value is left as it is). */
    public static function decodeString(?string $type, string $value): string
    {
        return $type === null || ! isset(self::PREFIXES[$type]) ? $value : self::decode($value, $type);
    }

    /** {@see encode()} for a kind that may be unknown (then the value is left as it is). */
    public static function encodeString(?string $type, string $value): string
    {
        return $type === null || ! isset(self::PREFIXES[$type]) ? $value : self::encode($type, $value);
    }

    /**
     * A registered public id's prefix and ULID; null for anything else (a bare ULID, a slug,
     * a `cid_` client id, a prefix nobody registered).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parse(string $value): ?array
    {
        if (preg_match('/\A([a-z]+)_('.self::ULID.')\z/', $value, $match) !== 1) {
            return null;
        }

        return in_array($match[1], self::PREFIXES, true) ? [$match[1], $match[2]] : null;
    }

    public static function isUlid(string $value): bool
    {
        return preg_match('/\A'.self::ULID.'\z/', $value) === 1;
    }

    /** Whether this is a registered public id of the given kind. */
    public static function isOf(string $type, string $value): bool
    {
        $parsed = self::parse($value);

        return $parsed !== null && $parsed[0] === self::prefix($type);
    }
}
