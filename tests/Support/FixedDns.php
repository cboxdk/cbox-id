<?php

declare(strict_types=1);

namespace Tests\Support;

use Cbox\Ssrf\Contracts\Resolver;
use Cbox\Ssrf\SystemResolver;
use Cbox\Ssrf\Testing\FakeResolver;

/**
 * DNS for the suite: deterministic, offline, and the same answer on every machine.
 *
 * WHY THIS EXISTS. The SSRF guard resolves a hostname before it will let a webhook,
 * SCIM or federation URL be stored, and the suite is built out of fictional hosts —
 * `hooks.tenant.example`, `scim.acme.test`, `okta.example`. None of them resolve, which
 * means those tests were passing only where something made the guard skip the check.
 *
 * That something was a line in one developer's `.env`: `CBOX_ID_WEBHOOKS_VERIFY_URL=false`.
 * With it, the local suite reported 1479 passing; CI, which copies `.env.example` and
 * therefore takes the default of `true`, failed the same commit on all four engines with
 * `host [hooks.tenant.example] does not resolve`. The tests that looked like they proved
 * the guard was wired up were proving nothing, on the machine where the work was done.
 *
 * The fix is not to turn the flag off everywhere — that would keep the guard untested and
 * make CI agree with a suite that checks nothing. It is to remove DNS from the question.
 * The package ships {@see Resolver} for exactly this, so the guard runs its
 * real logic — scheme allow-list, credential rejection, private and reserved ranges,
 * blocked hosts, IPv6 transition forms — against an answer the test states.
 *
 * DEFAULTS TO A PUBLIC ADDRESS, unlike {@see FakeResolver}, whose map answers
 * an empty list for anything it was not told about. An empty list means "does not
 * resolve", so a bare fake would refuse every host in the suite and the enumeration of
 * which hosts to pre-load would have to be kept in step with every test that invents one
 * — a list that is wrong the moment somebody adds a fixture. The default here is the
 * uninteresting answer (a public unicast address the guard has no objection to), so a
 * test only has to speak up when the DNS answer is the point.
 *
 * A test that wants the interesting answer says so:
 *
 *   app(Resolver::class)->set('rebind.example', ['169.254.169.254']);  // metadata service
 *   app(Resolver::class)->set('gone.example', []);                     // does not resolve
 *
 * The real {@see SystemResolver} is never bound in tests, so a host nobody
 * mapped cannot reach the network and cannot make a test depend on the resolver of the
 * machine it happens to run on.
 */
final class FixedDns implements Resolver
{
    /**
     * TEST-NET and documentation ranges are reserved, and a guard that classifies
     * reserved space as unsafe would refuse them — so this is an ordinary public
     * unicast address instead. It is never connected to: the guard inspects the
     * address, it does not dial it.
     */
    public const PUBLIC_ADDRESS = '93.184.216.34';

    /** @var array<string, list<string>> */
    private array $map = [];

    /**
     * @param  list<string>  $addresses  empty means "this host does not resolve"
     */
    public function set(string $host, array $addresses): self
    {
        $this->map[mb_strtolower($host)] = $addresses;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        return $this->map[mb_strtolower($host)] ?? [self::PUBLIC_ADDRESS];
    }
}
