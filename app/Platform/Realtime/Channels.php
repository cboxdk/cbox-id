<?php

declare(strict_types=1);

namespace App\Platform\Realtime;

use App\Http\Controllers\RealtimeAuthController;

/**
 * The three kinds of live channel, and the only names a channel may have.
 *
 * Every channel is PRIVATE: a browser joins one only with a signature from the page's own
 * host, minted by {@see RealtimeAuthController} behind the same gates
 * as the pages that listen on it.
 *
 *  - `id.environment.{environment}` — the environment console: everything in one environment.
 *  - `id.organization.{organization}` — an organization's console: what happens in it.
 *  - `id.subject.{subject}` — one person's own pages: their devices, sessions, grants and
 *    the approvals waiting for them.
 *
 * Ids are the records' own (ULIDs), so a name says nothing a signed-in page did not already
 * know. The platform root's environment is an environment like any other.
 */
final class Channels
{
    public const string ENVIRONMENT = 'environment';

    public const string ORGANIZATION = 'organization';

    public const string SUBJECT = 'subject';

    private const string PREFIX = 'id.';

    private const string ID = '[0-9A-Za-z]{1,64}';

    public static function environment(string $id): string
    {
        return self::PREFIX.self::ENVIRONMENT.'.'.$id;
    }

    public static function organization(string $id): string
    {
        return self::PREFIX.self::ORGANIZATION.'.'.$id;
    }

    public static function subject(string $id): string
    {
        return self::PREFIX.self::SUBJECT.'.'.$id;
    }

    /** The name as a Pusher-protocol client spells it when subscribing. */
    public static function private(string $channel): string
    {
        return 'private-'.$channel;
    }

    /**
     * What a client asked to join, read strictly: `private-id.{kind}.{id}` and nothing else.
     *
     * @return array{kind: string, id: string}|null
     */
    public static function parse(string $name): ?array
    {
        if (preg_match('/\Aprivate-id\.('.self::ENVIRONMENT.'|'.self::ORGANIZATION.'|'.self::SUBJECT.')\.('.self::ID.')\z/', $name, $match) !== 1) {
            return null;
        }

        return ['kind' => $match[1], 'id' => $match[2]];
    }

    public static function valid(string $id): bool
    {
        return preg_match('/\A'.self::ID.'\z/', $id) === 1;
    }
}
