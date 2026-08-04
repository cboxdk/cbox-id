<?php

declare(strict_types=1);

use App\Mail\MagicLinkMail;
use App\Mail\WorkspacePasswordResetMail;
use App\Platform\MailLinks;
use App\Platform\TrustedHosts;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * Host-header poisoning on the ACCOUNT plane.
 *
 * `SetEnvironment` answers an UNMAPPED host with the platform root rather than a refusal
 * (host hardening is punted to the ingress there, deliberately), so `Host: evil.example`
 * reaches every account-plane surface — including forgot-password and magic-link, both of
 * which mail an absolute URL that `route()` builds from that very header. The reset link
 * then survives replay, because Laravel's `signed` middleware recomputes the signature
 * over `$request->url()`, which is the poisoned host again.
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
    platformRootEnvironment();

    return app(AccountProvisioner::class)->provision(new AccountBlueprint(
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
    poisonRequestHost('http://evil.example/workspace/forgot-password');

    expect(route('workspace.password.request'))->toContain('evil.example');
});

it('never mails a reset link on a Host this deployment does not serve', function (): void {
    $account = accountPlaneMember();
    Mail::fake();

    poisonRequestHost('http://evil.example/workspace/forgot-password');

    Volt::test('workspace.forgot-password')
        ->set('email', $account->member->email)
        ->call('request')
        ->assertHasNoErrors();

    Mail::assertSent(WorkspacePasswordResetMail::class, function (WorkspacePasswordResetMail $mail): bool {
        expect($mail->url)
            ->not->toContain('evil.example')
            ->and($mail->url)->toStartWith((string) config('app.url'));

        return true;
    });
});

it('never mails a magic link on a Host this deployment does not serve', function (): void {
    $account = accountPlaneMember();
    Mail::fake();

    poisonRequestHost('http://evil.example/workspace/login');

    Volt::test('workspace.login')
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

it('refuses a signed reset link replayed with a foreign Host', function (): void {
    $account = accountPlaneMember();
    Mail::fake();

    // Requested through the poisoned host, which is the whole attack: without MailLinks
    // the signature would be computed over `evil.example` and the replay below would
    // validate — that is the step the reviewer used to reach a rendered reset form.
    poisonRequestHost('http://evil.example/workspace/forgot-password');

    Volt::test('workspace.forgot-password')
        ->set('email', $account->member->email)
        ->call('request')
        ->assertHasNoErrors();

    $url = '';
    Mail::assertSent(WorkspacePasswordResetMail::class, function (WorkspacePasswordResetMail $mail) use (&$url): bool {
        $url = $mail->url;

        return true;
    });

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

it('trusts nothing extra on a single-tenant deployment, so TrustHosts falls back to app.url', function (): void {
    // The lockout case. `patterns()` returning [] is SAFE and must stay safe: TrustHosts
    // is registered with `subdomains: true`, which adds `app.url` and everything under it
    // — the one host a self-hosted install serves.
    expect(app(TrustedHosts::class)->patterns())->toBe([])
        ->and(hostIsTrusted((string) parse_url((string) config('app.url'), PHP_URL_HOST)))->toBeFalse();
});
