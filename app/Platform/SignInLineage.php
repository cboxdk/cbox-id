<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * ONE SIGN-IN, EVERY HOST IT REACHED — so that signing out on any of them ends all of them.
 *
 * Opening an environment's admin console mints a handoff on the account host, and the
 * environment's host redeems it into a session of its own: a second platform-root session
 * row, behind a second cookie, on a second origin. Nothing tied the two together, which is
 * how "Log out" in a tenant console came undone in one redirect. The console's row was
 * ended, the console's gate bounced to the account host's open-environment door, and the
 * account host — whose row nobody had touched — minted a fresh handoff straight back in.
 *
 * This records the tie. The handoff carries a sealed list of the sessions the minting
 * browser held ({@see seal()}); the redemption opens it, refuses a handoff whose sign-in is
 * already over ({@see vouches()}), and links the new console session to it ({@see link()}).
 * Signing out then ends the FAMILY ({@see family()}): the sessions this browser holds on the
 * host where the button was pressed, the account-host sessions they were handed off from,
 * and every other console session handed off from those.
 *
 * WHY A FAMILY AND NOT EVERY SESSION THE PERSON HAS. "Sign out" on a laptop is not "sign out
 * my phone", and ending every session the person holds would also send a back-channel
 * logout to every application they use on every device. Revoking all of them is a separate,
 * deliberate act ("Sign out other sessions", an administrator, a password reset), and it
 * reaches the console sessions anyway: they are ordinary platform-root session rows.
 *
 * WHY THE SERVER, NOT A REDIRECT CHAIN. The account host's cookie is not readable on an
 * environment host, so a sign-out pressed there could only reach the account host by
 * bouncing the browser through it — a cross-site GET that ends a session, which is CSRF by
 * construction unless it is signed, single-use and bound, and which still leaves other tabs
 * holding a live session until they navigate. Revoking the rows is immediate, covers every
 * tab, and needs nothing from the browser.
 */
final class SignInLineage
{
    public const TABLE = 'session_lineage';

    /** The form field the sealed parents ride in, next to the handoff token. */
    public const FIELD = 'lineage';

    /** Distinguishes this payload from anything else sealed under the application key. */
    private const PURPOSE = 'cbox.handoff-lineage';

    /** A browser holds at most this many accounts ({@see PlatformAuth}); anything more is not ours. */
    private const MAX_PARENTS = 8;

    /** Lineage is at most two hops deep (account session → console session); this bounds a pathological graph. */
    private const MAX_HOPS = 4;

    private const MAX_FAMILY = 256;

    public function __construct(
        private readonly StringEncrypter $encrypter,
        private readonly SessionManager $sessions,
        private readonly PlatformRoot $platformRoot,
    ) {}

    /**
     * Seal the sessions a handoff is minted from, bound to that one token.
     *
     * Encrypted, not just signed: a session id is not a credential, but it has no business
     * being readable on the environment's host, whose domain the customer controls.
     *
     * @param  list<string>  $parentSessionIds
     */
    public function seal(string $token, array $parentSessionIds): string
    {
        return $this->encrypter->encryptString(json_encode([
            'v' => self::PURPOSE,
            't' => hash('sha256', $token),
            'p' => self::ids($parentSessionIds),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The sessions a handoff was minted from, or null when the seal is missing, tampered
     * with, or belongs to a different token.
     *
     * @return list<string>|null
     */
    public function open(string $token, mixed $sealed): ?array
    {
        if (! is_string($sealed) || $sealed === '' || strlen($sealed) > 8192) {
            return null;
        }

        try {
            $payload = json_decode($this->encrypter->decryptString($sealed), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        if (! is_array($payload)
            || ($payload['v'] ?? null) !== self::PURPOSE
            || ! is_string($payload['t'] ?? null)
            || ! hash_equals(hash('sha256', $token), $payload['t'])
            || ! is_array($payload['p'] ?? null)) {
            return null;
        }

        $parents = self::ids($payload['p']);

        return $parents === [] || count($parents) > self::MAX_PARENTS ? null : $parents;
    }

    /**
     * Whether one of these sessions is still a live sign-in OF THIS SUBJECT, in the
     * platform root. A handoff minted by a browser that has since signed out buys nothing,
     * even inside its two-minute lifetime, and a seal lifted from somebody else's handoff
     * vouches for nobody else.
     *
     * @param  list<string>  $parentSessionIds
     */
    public function vouches(string $subjectId, array $parentSessionIds): bool
    {
        return $this->platformRoot->run(function () use ($subjectId, $parentSessionIds): bool {
            foreach ($parentSessionIds as $id) {
                if ($this->sessions->active($id)?->user_id === $subjectId) {
                    return true;
                }
            }

            return false;
        }) === true;
    }

    /**
     * Record that a console session was handed off from these sessions.
     *
     * @param  list<string>  $parentSessionIds
     */
    public function link(string $sessionId, array $parentSessionIds): void
    {
        $now = now();

        $rows = array_map(
            static fn (string $parent): array => ['session_id' => $sessionId, 'parent_session_id' => $parent, 'created_at' => $now],
            array_values(array_diff(self::ids($parentSessionIds), [$sessionId])),
        );

        if ($rows !== []) {
            DB::table(self::TABLE)->insertOrIgnore($rows);
        }

        // Bounded by the deployment's session lifetime: no session a row names can still be
        // live past it, so the row can no longer end anything.
        $ttl = config('cbox-id.sessions.ttl_minutes', 60 * 24);
        DB::table(self::TABLE)
            ->where('created_at', '<', $now->copy()->subMinutes(is_numeric($ttl) ? max(1, (int) $ttl) : 60 * 24))
            ->delete();
    }

    /**
     * Every session in the same sign-in as these: the sessions themselves, the sessions
     * they were handed off from, and every session handed off from any of those.
     *
     * @param  list<string>  $sessionIds
     * @return list<string>
     */
    public function family(array $sessionIds): array
    {
        $family = self::ids($sessionIds);

        for ($hop = 0; $hop < self::MAX_HOPS && $family !== [] && count($family) < self::MAX_FAMILY; $hop++) {
            $parents = DB::table(self::TABLE)->whereIn('session_id', $family)->pluck('parent_session_id')->all();
            $children = DB::table(self::TABLE)->whereIn('parent_session_id', $family)->pluck('session_id')->all();

            $next = self::ids([...$family, ...$parents, ...$children]);

            if (count($next) === count($family)) {
                break;
            }

            $family = array_slice($next, 0, self::MAX_FAMILY);
        }

        return $family;
    }

    /**
     * End every one of these sessions — in the platform root, where account and console
     * sessions live, and in the ambient environment, where a tenant's own end-user
     * sessions on this host live. A row that is not in a scope is a no-op there.
     *
     * Through {@see SessionManager::revoke()} one row at a time, because that is what tells
     * the applications each session signed the person in to (back-channel logout).
     *
     * @param  list<string>  $sessionIds
     */
    public function revoke(array $sessionIds): void
    {
        foreach (self::ids($sessionIds) as $id) {
            $this->platformRoot->run(function () use ($id): bool {
                $this->sessions->revoke($id);

                return true;
            });

            $this->sessions->revoke($id);
        }
    }

    /**
     * End these sessions in the ambient environment ONLY — a tenant's own end users, signing
     * out on the tenant's host. Nothing in the platform root is touched, so a row of the
     * administrator's that somehow sat among them is not ended by an end user's sign-out.
     *
     * @param  list<string>  $sessionIds
     */
    public function revokeHere(array $sessionIds): void
    {
        foreach (self::ids($sessionIds) as $id) {
            $this->sessions->revoke($id);
        }
    }

    /**
     * @param  array<mixed>  $ids
     * @return list<string>
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(
            $ids,
            static fn (mixed $id): bool => is_string($id) && $id !== '' && strlen($id) <= 64,
        )));
    }
}
