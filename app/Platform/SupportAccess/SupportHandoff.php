<?php

declare(strict_types=1);

namespace App\Platform\SupportAccess;

use App\Platform\SupportAccess\ValueObjects\SupportHandoffEntry;
use Illuminate\Contracts\Session\Session;

/**
 * Which support session THIS BROWSER holds, per app.
 *
 * Written when an administrator starts a session from the console and read when the app's
 * sign-in arrives at the authorization endpoint in the same browser. It is a pointer, never
 * the authority: the framework re-reads the session (active, this actor, this app) every
 * time a code is minted for it, and the console re-checks the administrator on every read.
 *
 * In the server-side session rather than a cookie of its own, so it goes wherever the
 * administrator's console session goes — signing out of the console ends it with the rest.
 */
class SupportHandoff
{
    private const KEY = 'cbox.support_handoff';

    public function __construct(private readonly Session $session) {}

    public function put(string $clientId, string $sessionId, string $actorId): void
    {
        $all = $this->all();
        $all[$clientId] = ['session' => $sessionId, 'actor' => $actorId];

        $this->session->put(self::KEY, $all);
    }

    public function for(string $clientId): ?SupportHandoffEntry
    {
        $entry = $this->all()[$clientId] ?? null;

        return $entry === null ? null : new SupportHandoffEntry($clientId, $entry['session'], $entry['actor']);
    }

    public function forgetApp(string $clientId): void
    {
        $all = $this->all();
        unset($all[$clientId]);

        $this->session->put(self::KEY, $all);
    }

    public function forgetSession(string $sessionId): void
    {
        $this->session->put(self::KEY, array_filter(
            $this->all(),
            static fn (array $entry): bool => $entry['session'] !== $sessionId,
        ));
    }

    /**
     * What is stored, re-validated on the way out: the session is server-side, but a shape
     * this class did not write is not one it will act on.
     *
     * @return array<string, array{session: string, actor: string}>
     */
    private function all(): array
    {
        $raw = $this->session->get(self::KEY);

        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $clientId => $entry) {
            if (is_string($clientId)
                && is_array($entry)
                && is_string($entry['session'] ?? null)
                && is_string($entry['actor'] ?? null)) {
                $out[$clientId] = ['session' => $entry['session'], 'actor' => $entry['actor']];
            }
        }

        return $out;
    }
}
