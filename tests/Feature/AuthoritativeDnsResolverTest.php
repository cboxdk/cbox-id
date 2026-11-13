<?php

declare(strict_types=1);

use App\Platform\AuthoritativeDnsResolver;
use App\Platform\Dns\DeadlineResolver;
use Cbox\Dns\Contracts\Resolver;
use Cbox\Dns\Enums\RecordType;
use Cbox\Dns\Exceptions\ResolutionFailed;
use Cbox\Dns\Resolvers\AuthoritativeResolver;
use Cbox\Dns\Testing\FakeResolver;
use Cbox\Dns\ValueObjects\DnsResponse;
use Cbox\Id\Federation\Contracts\DnsResolver;

it('reads the challenge TXT from the zone authoritative nameservers', function (): void {
    // acme.test delegates to ns1 (a public IP so the SSRF filter allows it); the
    // verification TXT lives on the challenge host, served authoritatively.
    $fake = (new FakeResolver)
        ->stub('acme.test', RecordType::NS, ['ns1.acme.test'])
        ->stub('ns1.acme.test', RecordType::A, ['8.8.8.8'])
        ->stub('_cbox-id-challenge.acme.test', RecordType::TXT, ['cbox-id-verify=abc123'], nameserver: '8.8.8.8');

    $resolver = new AuthoritativeDnsResolver(new AuthoritativeResolver($fake));

    expect($resolver->txtRecords('_cbox-id-challenge.acme.test'))->toContain('cbox-id-verify=abc123');
});

it('walks up past a non-delegated parent to the real zone cut', function (): void {
    // The challenge host sits under sub.acme.test, but only acme.test is a zone
    // (sub has no NS). The resolver must fail on the sub candidate and walk up.
    $fake = (new FakeResolver)
        ->stub('acme.test', RecordType::NS, ['ns1.acme.test'])
        ->stub('ns1.acme.test', RecordType::A, ['8.8.8.8'])
        ->stub('_cbox-id-challenge.sub.acme.test', RecordType::TXT, ['cbox-id-verify=deep'], nameserver: '8.8.8.8');

    $resolver = new AuthoritativeDnsResolver(new AuthoritativeResolver($fake));

    expect($resolver->txtRecords('_cbox-id-challenge.sub.acme.test'))->toContain('cbox-id-verify=deep');
});

it('fails closed (no records) when the zone has no reachable authoritative nameserver', function (): void {
    $resolver = new AuthoritativeDnsResolver(new AuthoritativeResolver(new FakeResolver));

    expect($resolver->txtRecords('_cbox-id-challenge.unknown.test'))->toBe([]);
});

it('binds the authoritative resolver as the framework DnsResolver contract', function (): void {
    expect(app(DnsResolver::class))->toBeInstanceOf(AuthoritativeDnsResolver::class);
});

/*
 * Needs-validation lead: a domain's owner publishes 20 NS names with 8 addresses each that
 * silently drop DNS, and one verify tried all 160 one after another at ~6 s each: a web
 * worker (and on the action path an open transaction) held for up to php-fpm's 60 s
 * request_terminate_timeout, per request, from any tenant admin or Admin Portal link. The
 * whole lookup now has one deadline; past it, the answer is "not verified yet".
 */
it('gives a verify one deadline, however many silent nameservers a zone lists', function (): void {
    $now = 0.0;
    $direct = 0;

    $nameservers = array_map(static fn (int $n): string => "ns{$n}.hostile.test", range(1, 20));
    $recursive = (new FakeResolver)->stub('hostile.test', RecordType::NS, $nameservers);
    foreach ($nameservers as $i => $ns) {
        $recursive->stub($ns, RecordType::A, array_map(static fn (int $n): string => '8.8.'.($i + 1).'.'.$n, range(1, 8)));
    }

    $silent = new class($recursive, $now, $direct) implements Resolver
    {
        public function __construct(private Resolver $recursive, private float &$now, private int &$direct) {}

        public function query(string $host, RecordType $type, ?string $nameserver = null, bool $recursion = true, bool $dnssec = false): DnsResponse
        {
            if ($nameserver === null) {
                return $this->recursive->query($host, $type);
            }

            $this->direct++;
            $this->now += 6.0; // a silent server: timeout × attempts

            throw ResolutionFailed::make($nameserver, 'timed out');
        }
    };

    $deadline = new DeadlineResolver($silent, AuthoritativeDnsResolver::DEADLINE_SECONDS, static function () use (&$now): float {
        return $now;
    });
    $resolver = new AuthoritativeDnsResolver(new AuthoritativeResolver($deadline), $deadline);

    expect($resolver->txtRecords('_cbox-id-challenge.hostile.test'))->toBe([])
        ->and($now)->toBeLessThanOrEqual(AuthoritativeDnsResolver::DEADLINE_SECONDS + 6.0)
        ->and($direct)->toBeLessThan(5);
})->group('security');

it('starts each verify with a fresh deadline', function (): void {
    $now = 0.0;
    $fake = (new FakeResolver)
        ->stub('acme.test', RecordType::NS, ['ns1.acme.test'])
        ->stub('ns1.acme.test', RecordType::A, ['8.8.8.8'])
        ->stub('_cbox-id-challenge.acme.test', RecordType::TXT, ['cbox-id-verify=abc123'], nameserver: '8.8.8.8');

    $deadline = new DeadlineResolver($fake, AuthoritativeDnsResolver::DEADLINE_SECONDS, static function () use (&$now): float {
        return $now;
    });
    $resolver = new AuthoritativeDnsResolver(new AuthoritativeResolver($deadline), $deadline);

    $now = 1000.0; // long after any earlier verify
    expect($resolver->txtRecords('_cbox-id-challenge.acme.test'))->toContain('cbox-id-verify=abc123');
});
