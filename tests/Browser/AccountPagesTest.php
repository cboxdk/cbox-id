<?php

declare(strict_types=1);

use App\Platform\AppCatalog\CatalogEntry;
use App\Platform\PlatformAuth;
use App\Platform\Surfaces\SessionSurface;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Contracts\TotpDevices;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Str;

/**
 * A PERSON'S OWN PAGES, AS SOMEBODY WITH A FULL ACCOUNT SEES THEM.
 *
 * One person with three organizations, a dozen apps, two passkeys, an authenticator app
 * and sessions on other devices, signed in on the login host (`127.0.0.1` here). Every page
 * of theirs is opened in light and dark, on a desktop and on a 390px phone, and each must
 * render without a JavaScript error, without a page wider than the screen, and without a
 * raw value (an enum, a key, a ULID) where a person reads words.
 *
 * The pictures (tests/Browser/Screenshots/account-*.png) are for people reviewing a
 * change by eye; nothing compares them.
 */
beforeEach(function (): void {
    config([
        'cbox-id.tenancy.multi_tenant' => true,
        'cbox-id.tenancy.account_host' => 'localhost',
        'cbox-id.hosts.login' => '127.0.0.1',
        'id-devices.enabled' => true,
    ]);

    installedDeployment();
});

/** @var list<array{0: string, 1: string, 2: string|null}> */
const ACCOUNT_APPS = [
    ['billing', 'Billing', 'Finance'],
    ['invoices', 'Invoices', 'Finance'],
    ['payroll', 'Payroll', 'Finance'],
    ['crm', 'Customers', 'Sales'],
    ['quotes', 'Quotes', 'Sales'],
    ['helpdesk', 'Helpdesk', 'Support'],
    ['status', 'Status page', 'Support'],
    ['docs', 'Team docs', 'Collaboration'],
    ['chat', 'Team chat', 'Collaboration'],
    ['calendar', 'Calendar', 'Collaboration'],
    ['analytics', 'Analytics', null],
    ['deploy', 'Deployments', null],
    ['storage', 'File storage', null],
];

/**
 * The person, their organizations, apps, credentials and sessions, signed in on the login
 * host. Returns their subject id.
 */
function fullAccount(): string
{
    $account = provisionAccount('ada@acme.test');
    platformRootDeployment();
    $subjectId = $account['subjectId'];

    app(PlatformRoot::class)->run(function () use ($subjectId, $account): void {
        app(Subjects::class)->update($subjectId, name: 'Ada Lovelace');
        app(Subjects::class)->markEmailVerified($subjectId, 'ada@acme.test');

        foreach ([['Northwind Traders', MembershipRole::Admin], ['Globex', MembershipRole::Member]] as [$name, $role]) {
            $organization = app(Organizations::class)->create(new NewOrganization($name, Str::slug($name).'-'.Str::lower(Str::random(4))));
            app(Memberships::class)->add($organization->id, $subjectId, $role);
        }

        // An invitation waiting for her address, to join from her own page.
        $initech = app(Organizations::class)->create(new NewOrganization('Initech', 'initech-'.Str::lower(Str::random(4))));
        app(Invitations::class)->invite($initech->id, 'ada@acme.test', MembershipRole::Member);

        foreach (ACCOUNT_APPS as $position => [$key, $name, $purpose]) {
            $client = app(ClientRegistry::class)->register(new NewClient(
                name: $name,
                type: ClientType::Confidential,
                redirectUris: ["https://{$key}.acme.example/auth/callback"],
            ))->client;

            CatalogEntry::query()->create([
                'key' => $key,
                'name' => $name,
                'description' => $purpose === null ? null : "{$name} for the whole team.",
                'launch_url' => "https://{$key}.acme.example/login",
                'enabled' => true,
                'position' => $position,
                'audience' => 'everyone',
                'organization_ids' => [],
                'user_ids' => [],
                'client_ids' => [$client->client_id],
                'purpose' => $purpose,
                'version' => 1,
            ]);

            // Three of them used recently, so "Recently used" has something in it.
            if (in_array($key, ['helpdesk', 'billing', 'docs'], true)) {
                app(RefreshTokens::class)->issue(
                    $client,
                    $subjectId,
                    $account['organization']->id,
                    ['openid', 'profile', 'email'],
                );
            }
        }

        foreach ([['MacBook Pro', 'cred-mac'], ['iPhone', 'cred-iphone']] as [$name, $credential]) {
            WebAuthnCredential::query()->create([
                'user_id' => $subjectId,
                'credential_id' => $credential,
                'public_key' => 'pk',
                'name' => $name,
                'sign_count' => 0,
            ]);
        }

        $devices = app(TotpDevices::class);
        $totp = $devices->enrollDevice($subjectId, 'ada@acme.test', '1Password');
        $devices->confirmDevice($subjectId, $totp->id, app(TotpAuthenticator::class)->codeAt($totp->secret, time() - 30));

        $sessions = app(SessionManager::class);
        $sessions->start($subjectId, $account['organization']->id, ['passkey'], '192.0.2.10', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1');
        $sessions->start($subjectId, $account['organization']->id, ['pwd', 'otp', 'mfa'], '198.51.100.7', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36');
    });

    $session = app(SessionManager::class)->start($subjectId, $account['organization']->id, ['pwd', 'device', 'mfa']);
    signInAsMember($subjectId);
    session([PlatformAuth::SESSION_KEY => $session->id, SessionSurface::KEY => 'login']);

    expect(app(PlatformRoot::class)->run(fn (): ?string => app(Subjects::class)->find($subjectId)?->name))->toBe('Ada Lovelace');

    return $subjectId;
}

/** Every one of the person's pages, by the name its picture is saved under. */
const ACCOUNT_PAGES = [
    'apps' => ['/settings/apps', 'Recently used'],
    'security' => ['/settings', 'Sign-in methods'],
    'activity' => ['/settings/activity', 'Sessions'],
    'devices' => ['/settings/devices', 'Trusted devices'],
    'preferences' => ['/settings/preferences', 'Time zone'],
    'accounts' => ['/settings/accounts', 'Ada Lovelace'],
];

it('draws every page of a full account, light and dark, desktop and phone', function (string $theme, string $size): void {
    fullAccount();
    [$width, $height] = $size === 'phone' ? [390, 844] : [1440, 900];

    foreach (ACCOUNT_PAGES as $name => [$path, $text]) {
        $page = visit($path.'?return_to='.urlencode('https://billing.acme.example/invoices'))->resize($width, $height);
        $page->assertSee($text);
        $page->script("document.documentElement.classList.toggle('dark', '{$theme}' === 'dark'); document.documentElement.setAttribute('data-theme', '{$theme}')");
        $page->assertNoJavaScriptErrors();

        expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue("{$name} is wider than the screen");

        $page->screenshot(true, "account-{$name}-{$theme}-{$size}");
    }
})->with(['light', 'dark'])->with(['desktop', 'phone']);

it('finds an app by name or purpose, and says so when nothing matches', function (): void {
    fullAccount();

    $page = visit('/settings/apps')->resize(390, 844);

    $page->assertSee('Recently used')
        ->assertSee('Finance')
        ->fill('[data-test="apps-search"]', 'support')
        ->assertSee('Helpdesk')
        ->assertSee('Status page')
        ->assertDontSee('Payroll')
        ->assertDontSee('Recently used')
        ->fill('[data-test="apps-search"]', 'zebra')
        ->assertSee('No apps match')
        ->click('Clear search')
        ->assertSee('Payroll')
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues();

    // A tile is the app's own launch address, opened in the organization the person is in.
    expect($page->script('document.querySelector(\'[data-test="launcher-tile"]\').getAttribute("href")'))
        ->toMatch('#^https://[a-z]+\.acme\.example/login\?organization=#');
});

it('joins an organization it was invited to from the security page', function (): void {
    fullAccount();

    visit('/settings')
        ->assertSeeIn('[data-test="account-invitations"]', 'Initech')
        ->click('Join')
        ->assertSee('You joined Initech.')
        ->assertNotPresent('[data-test="account-invitations"]')
        ->assertNoJavaScriptErrors();
});
