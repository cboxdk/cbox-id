<?php

declare(strict_types=1);

use App\Platform\AuthoritativeDnsResolver;
use Cbox\Dns\Enums\RecordType;
use Cbox\Dns\Resolvers\AuthoritativeResolver;
use Cbox\Dns\Testing\FakeResolver;
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

it('fails closed (no records) when the zone has no reachable authoritative nameserver', function (): void {
    $resolver = new AuthoritativeDnsResolver(new AuthoritativeResolver(new FakeResolver));

    expect($resolver->txtRecords('_cbox-id-challenge.unknown.test'))->toBe([]);
});

it('binds the authoritative resolver as the framework DnsResolver contract', function (): void {
    expect(app(DnsResolver::class))->toBeInstanceOf(AuthoritativeDnsResolver::class);
});
