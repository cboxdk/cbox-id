<?php

declare(strict_types=1);

use App\Mail\AccountInviteMail;
use App\Mail\MagicLinkMail;
use App\Mail\PasswordResetMail;
use App\Platform\MailLinks;
use App\Platform\TrustedHosts;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * Host-header poisoning on the platform root.
 *
 * `SetEnvironment` answers an UNMAPPED host with the platform root rather than a refusal
 * (host hardening is punted to the ingress there, deliberately), so `Host: evil.example`
 * reaches every surface it serves — including forgot-password, magic-link and the account
 * invitation, each of which mails an absolute URL that `route()` builds from that very
 * header. The invitation then survives replay, because Laravel's `signed` middleware
 * recomputes the signature over `$request->url()`, which is the poisoned host again.
 *
 * The account plane had its own copy of the first two doors and this file held both sets.
 * There is one of each now, and it is the same one every tenant uses — so what is asserted
 * here is that a MEMBER's mail goes through it, which is the claim the second copy was
 * standing in for.
 *
 * Two layers answer this and they fail differently, so both are held here:
 *
 *  - {@see TrustedHosts} + `trustHosts()` in bootstrap/app.php refuses the header outright.
 *    NOT reachable from a test: `TrustHosts::shouldSpecifyTrustedHosts()` is a no-op under
 *    `runningUnitTests()`, so the derivation is asserted directly instead — which is the
 *    part that can actually be wrong, since Symfony matches these UNANCHORED.
 *  - {@see MailLinks} forces the origin from `app.url` for anything MAILED, so a
 *    misconfigured ingress still cannot reach an email body. That half is end-to-end here.
 */

/** The account plane's shape: multi-tenant, a platform root, one account with a member. */
function accountPlaneMember(): object
{
    multiTenantDeployment('cboxid.com');
    // Stand the request IN the platform root, not merely beside it: a member's subject and
    // its reset tokens live there, and under the suite's default environment the
    // deny-by-default scope answers the door with "no such address" — which mails nothing
    // and makes an assertion about a mail's origin pass for the wrong reason.
    platformRootDeployment();

    return app(TenantProvisioner::class)->provision(new TenantBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));
}

/**
 * Point the URL generator (and therefore `route()`) at a host, the way an inbound request
 * with that `Host:` header would.
 *
 * The generator is what both the vulnerability and the fix run through, so poisoning it is
 * poisoning the real thing rather than a stand-in — `route()` genuinely returns the
 * attacker's origin afterwards, which the tests below assert before they assert anything
 * else. Without that check a passing test could just mean the poisoning never landed.
 */
function poisonRequestHost(string $url): void
{
    $request = Request::create($url, 'POST');

    app()->instance('request', $request);
    URL::setRequest($request);
}

it('is genuinely poisonable — route() follows the Host header', function (): void {
    accountPlaneMember();
    poisonRequestHost('http://evil.example/forgot-password');

    expect(route('password.request'))->toContain('evil.example');
});

it('never mails a reset link on a Host this deployment does not serve', function (): void {
    $account = accountPlaneMember();
    Mail::fake();

    poisonRequestHost('http://evil.example/forgot-password');

    Volt::test('auth.forgot-password')
        ->set('email', $account->member->email)
        ->call('sendResetLink')
        ->assertHasNoErrors();

    Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail): bool {
        expect($mail->url)
            ->not->toContain('evil.example')
            ->and($mail->url)->toStartWith((string) config('app.url'));

        return true;
    });
});

it('never mails a magic link on a Host this deployment does not serve', function (): void {
    $account = accountPlaneMember();
    Mail::fake();

    poisonRequestHost('http://evil.example/login');

    Volt::test('auth.login')
        ->set('email', $account->member->email)
        ->call('sendMagicLink')
        ->assertHasNoErrors();

    // The magic link is the worse half of the pair: it carries a BEARER token in the
    // path, so a poisoned origin needs no replay trick — one click hands the token over.
    Mail::assertSent(MagicLinkMail::class, function (MagicLinkMail $mail): bool {
        expect($mail->url)
            ->not->toContain('evil.example')
            ->and($mail->url)->toStartWith((string) config('app.url'));

        return true;
    });
});

it('refuses a signed invitation link replayed with a foreign Host', function (): void {
    $account = accountPlaneMember();
    Mail::fake();

    // Single-host from here on. What is under test is the ORIGIN a mailed link carries and
    // whether its signature survives a replay — not which hosts serve which surface. Left
    // multi-tenant, the link `MailLinks` correctly mints from `app.url` points at a host
    // this fixture's SaaS shape does not answer on, and the 404 would read as the signature
    // holding when nothing had been checked at all.
    config(['cbox-id.tenancy.multi_tenant' => false]);

    // Minted through the poisoned host, which is the whole attack: without MailLinks the
    // signature would be computed over `evil.example` and the replay below would validate
    // — that is the step the reviewer used to reach a rendered form holding a credential.
    //
    // The invitation rather than the reset, because it is the signed mailed route that is
    // left: the account plane's `signed` reset is gone, and the console's reset carries a
    // token in the path rather than a signature over the URL.
    poisonRequestHost('http://evil.example/account-members');

    $invited = app(Memberships::class)
        ->invite((string) $account->account->id, 'invitee@acme.example', MembershipRole::Admin);

    $url = app(MailLinks::class)->temporarySignedRoute(
        'account.invite.accept',
        now()->addDays(7),
        ['member' => $invited->id],
    );

    Mail::to($invited->email)->send(new AccountInviteMail('Acme', 'Owner', $url));

    expect($url)->not->toContain('evil.example')
        ->and($url)->toStartWith((string) config('app.url'));

    $path = (string) parse_url($url, PHP_URL_PATH).'?'.(string) parse_url($url, PHP_URL_QUERY);

    // On the host it was minted for, it opens. Asserted FIRST: a signature check that
    // refuses everything is not a control, it is a broken link.
    $this->get($url)->assertOk();

    // Replayed with the attacker's Host still set — the step that completed the proven
    // chain, because the `signed` middleware recomputes over `$request->url()`.
    $this->get('http://evil.example'.$path)->assertForbidden();
});

it('keeps a tenant host minting its own links', function (): void {
    // A resolvable host is one this deployment was configured to answer on, so it stands:
    // mailing a tenant's users a link on the platform apex would be a regression.
    platformRootEnvironment();
    $tenant = Environment::query()->create([
        'name' => 'Tenant',
        'slug' => 'tenant-mail-links',
        'type' => EnvironmentType::Production,
        'status' => EnvironmentStatus::Active,
        'is_default' => false,
        'domain' => 'id.customer.example',
        'domain_verified_at' => now(),
        'settings' => [],
    ]);

    expect($tenant->domain)->toBe('id.customer.example');

    poisonRequestHost('https://id.customer.example/login');

    expect(app(MailLinks::class)->route('login'))->toStartWith('https://id.customer.example');
});

it('mints on the deployment host in the single-tenant shape', function (): void {
    // The suite's pinned shape: no base domains, no account host, one host for everything.
    installedDeployment();

    expect(app(MailLinks::class)->route('login'))->toStartWith((string) config('app.url'));
});

/**
 * Symfony compiles each trusted-host entry to `{pattern}i` and matches it UNANCHORED, so
 * an entry that is a bare hostname trusts every name containing it. That is the one thing
 * about this list that is easy to get wrong and impossible to see.
 */
function hostIsTrusted(string $host): bool
{
    foreach (app(TrustedHosts::class)->patterns() as $pattern) {
        if (preg_match('{'.$pattern.'}i', $host) === 1) {
            return true;
        }
    }

    return false;
}

it('derives the trusted hosts from the deployment, and anchors them', function (): void {
    multiTenantDeployment('cboxid.com');
    config()->set('cbox-id.environments.base_domains', ['cboxid.com']);
    config()->set('cbox-id.environments.resolution_cache_ttl', 0);

    platformRootEnvironment();
    Environment::query()->create([
        'name' => 'Tenant',
        'slug' => 'acme',
        'type' => EnvironmentType::Production,
        'status' => EnvironmentStatus::Active,
        'is_default' => false,
        'domain' => 'id.customer.example',
        'domain_verified_at' => now(),
        'settings' => [],
    ]);

    expect(hostIsTrusted('cboxid.com'))->toBeTrue()
        ->and(hostIsTrusted('acme.cboxid.com'))->toBeTrue()
        ->and(hostIsTrusted('id.customer.example'))->toBeTrue()
        ->and(hostIsTrusted('evil.example'))->toBeFalse()
        // The unanchored trap: a bare `cboxid.com` entry matches both of these.
        ->and(hostIsTrusted('cboxid.com.evil.example'))->toBeFalse()
        ->and(hostIsTrusted('evil-cboxid-com.example'))->toBeFalse();
});

it('invents no public host on a single-tenant deployment, so TrustHosts falls back to app.url', function (): void {
    // The lockout case. Deriving no public name is SAFE and must stay safe: TrustHosts is
    // registered with `subdomains: true`, which adds `app.url` and everything under it —
    // the one host a self-hosted install serves.
    //
    // This asserted `patterns() === []` until the list gained the addresses a container
    // reaches ITSELF by, without which a Kubernetes liveness probe 400s and crash-loops
    // every pod. Relaxing that assertion was not free — an empty list is exactly what a
    // self-hosted install produces — so it is restated as BEHAVIOUR rather than as the
    // shape of the array: no public name is invented here. Introspecting the strings is
    // what made the old assertion brittle in the first place.
    expect(hostIsTrusted((string) parse_url((string) config('app.url'), PHP_URL_HOST)))->toBeFalse()
        ->and(hostIsTrusted('cboxid.com'))->toBeFalse()
        ->and(hostIsTrusted('acme.cboxid.com'))->toBeFalse()
        ->and(hostIsTrusted('evil.example'))->toBeFalse()
        ->and(hostIsTrusted('8.8.8.8'))->toBeFalse();
});
