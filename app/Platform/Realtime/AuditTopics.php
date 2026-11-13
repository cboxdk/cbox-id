<?php

declare(strict_types=1);

namespace App\Platform\Realtime;

/**
 * The topics an audited act touches, read from its action name.
 *
 * Most changes are already live through the record they write ({@see RecordTopics}); this
 * covers the ones made in bulk, where no model event fires — "sign out everywhere" is one
 * UPDATE and one `user.sessions_revoked` entry — and the ones whose only trace is the
 * entry itself. Every entry is also `audit`, for the audit and activity pages.
 *
 * Longest prefix first; the first match wins.
 */
final class AuditTopics
{
    /** @var array<string, list<string>> */
    private const array PREFIXES = [
        'user.session' => ['sessions'],
        'user.login' => ['sessions'],
        'user.mfa' => ['credentials'],
        'user.passkey' => ['credentials'],
        'user.identity' => ['credentials'],
        'user.password' => ['credentials'],
        'identity.' => ['credentials'],
        'device.' => ['devices'],
        'user.' => ['users'],
        'organization.invitation' => ['members'],
        'organization.' => ['organizations'],
        'invitation.' => ['members'],
        'membership.' => ['members'],
        'member.' => ['members', 'roles'],
        'role.' => ['roles'],
        'client.' => ['apps'],
        'app.' => ['apps'],
        'service_account.' => ['apps'],
        'oauth.' => ['tokens'],
        'governance.access' => ['roles', 'members'],
    ];

    /** @return list<string> */
    public static function for(string $action): array
    {
        foreach (self::PREFIXES as $prefix => $topics) {
            if (str_starts_with($action, $prefix)) {
                return ['audit', ...$topics];
            }
        }

        return ['audit'];
    }
}
