<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * SEPARATE COOKIE JARS for a test that crosses hosts or devices.
 *
 * Laravel's test client keeps ONE session for every request a test makes, whatever host
 * the URL names — so a test that signs in on the account host and then visits an
 * environment's host is quietly using the account host's cookie there too, which no browser
 * would. That hides exactly the bugs that live between hosts: a sign-out on one host that
 * leaves the other signed in looks like a sign-out everywhere, because there is only one
 * "everywhere" to look at.
 *
 * A jar is named for what it stands for — `laptop@cboxid.com`, `laptop@acme.cboxid.com`,
 * `phone@cboxid.com` — and {@see use()} swaps the session's contents to that jar's, as a
 * browser would send that host's cookie and no other.
 */
final class Browsers
{
    /** @var array<string, array<string, mixed>> */
    private array $jars = [];

    private ?string $current = null;

    /** Make every following request carry this jar's session, and only this jar's. */
    public function use(string $jar): self
    {
        if ($this->current === $jar) {
            return $this;
        }

        if ($this->current !== null) {
            $this->jars[$this->current] = session()->all();
        }

        app()->forgetScopedInstances();
        session()->flush();
        session()->put($this->jars[$jar] ?? []);

        $this->current = $jar;

        return $this;
    }
}
