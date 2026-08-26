<?php

declare(strict_types=1);

namespace App\Platform\OAuth;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * THE VALIDATED AUTHORIZATION REQUEST, HELD SERVER-SIDE BETWEEN THE RENDER AND THE CLICK.
 *
 * The browser is handed an opaque id and nothing else. Everything that decides what a code
 * is minted for — the client, the redirect URI, the scopes, the PKCE challenge, the
 * audience — stays here, so the page cannot influence any of it. That replaces Volt's
 * `#[Locked]`, and it is stronger: `#[Locked]` refuses a mutation, while a value the client
 * never receives cannot be mutated at all.
 *
 * KEYED, NOT SINGULAR, because a person legitimately has two of these open. A single slot
 * would let a click in one tab approve the request drawn in another — the same class of
 * confusion the lock existed to prevent, reintroduced by the storage.
 *
 * BOUNDED, because this lives in the session and a client that opens `/authorize` in a loop
 * would otherwise grow a cookie until the browser refuses it. The oldest entries fall off;
 * a request whose entry has fallen off is refused and restarted, which is what an expired
 * one already does.
 */
final class PendingAuthorizations
{
    private const KEY = 'oauth.pending';

    /** How many open authorization requests one session may hold at once. */
    private const LIMIT = 5;

    /** @return string the id the page carries */
    public function put(Request $request, PendingAuthorization $pending): string
    {
        $id = (string) Str::ulid();

        $all = $this->all($request);
        $all[$id] = $pending->toArray();

        // Oldest first — ULIDs sort chronologically, so this is the insertion order.
        if (count($all) > self::LIMIT) {
            $all = array_slice($all, -self::LIMIT, preserve_keys: true);
        }

        $request->session()->put(self::KEY, $all);

        return $id;
    }

    public function find(Request $request, string $id): ?PendingAuthorization
    {
        $data = $this->all($request)[$id] ?? null;

        return $data === null ? null : PendingAuthorization::fromArray($data);
    }

    /**
     * Spend one. Approving or denying ends the request either way, so the entry goes with
     * it — a second click on a stale tab must not mint a second code from one consent.
     */
    public function forget(Request $request, string $id): void
    {
        $all = $this->all($request);

        unset($all[$id]);

        $request->session()->put(self::KEY, $all);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function all(Request $request): array
    {
        $stored = $request->session()->get(self::KEY, []);

        if (! is_array($stored)) {
            return [];
        }

        /** @var array<string, array<string, mixed>> $all */
        $all = [];

        foreach ($stored as $id => $entry) {
            if (! is_string($id) || ! is_array($entry)) {
                continue;
            }

            /** @var array<string, mixed> $fields */
            $fields = [];

            foreach ($entry as $field => $value) {
                if (is_string($field)) {
                    $fields[$field] = $value;
                }
            }

            $all[$id] = $fields;
        }

        return $all;
    }
}
