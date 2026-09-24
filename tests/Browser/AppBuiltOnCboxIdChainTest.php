<?php

declare(strict_types=1);

use App\Mail\InvitationMail;
use App\Platform\SelfServiceSignup;
use Cbox\Id\AccessControl\Contracts\AppManifests;
use Cbox\Id\AccessControl\Manifest\DeclaredPermission;
use Cbox\Id\AccessControl\Manifest\DeclaredRole;
use Cbox\Id\AccessControl\Manifest\Manifest;
use Cbox\Id\Identity\Contracts\BreachedPasswordCheck;
use Cbox\Id\Identity\NeverBreachedCheck;
use Cbox\Id\Kernel\Crypto\Contracts\TokenSigner;
use Cbox\Id\Kernel\Crypto\Enums\SigningAlg;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Support\Facades\Mail;

/**
 * THE APP-ON-CBOX-ID CHAIN, WALKED THROUGH ITS PAGES.
 *
 * tests/Feature/AppBuiltOnCboxIdChainTest.php proves every link with requests. This walks
 * the first three the way the people in them do — Anna signing up from the app and
 * founding a second team, inviting Bo as an Editor from her People page, Bo accepting on
 * the confirmation page — and checks each time the browser ends up back at the app, with
 * a code whose tokens say what they should.
 *
 * The app's pages are addresses on this server's own loopback host: nothing answers them
 * but a 404, which is fine — the address the browser arrives at IS the assertion.
 */
const BROWSER_CHAIN_VERIFIER = 'the-browser-chain-verifier-of-sufficient-length-0123456789';

beforeEach(function (): void {
    app()->instance(BreachedPasswordCheck::class, new NeverBreachedCheck);
    Mail::fake();
});

/**
 * The vendor environment on this server's host, sign-up open, and its cboxtax app.
 *
 * @return array{clientId: string, secret: string, base: string}
 */
function browserChainWorld(): array
{
    multiTenantDeployment();
    platformRootEnvironment();

    $vendor = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Cboxtax',
        ownerEmail: 'owner@vendor.test',
        ownerName: 'Vendor Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));
    $environment = serveOnTestHost($vendor->environment);

    expect($environment->domain)->not->toBeNull('the vendor environment could not take the browser host');

    app(EnvironmentContext::class)->set(GenericEnvironment::of($environment->id));
    app(SelfServiceSignup::class)->set($environment, true);

    $base = rtrim((string) config('app.url'), '/');

    $registered = app(ClientRegistry::class)->register(new NewClient(
        'cboxtax',
        ClientType::Confidential,
        redirectUris: [$base.'/cboxtax/callback'],
        grantTypes: ['authorization_code'],
        scopes: ['openid', 'profile', 'email'],
        firstParty: true,
    ));

    app(AppManifests::class)->sync($registered->client->client_id, new Manifest(
        version: '1',
        permissions: [
            new DeclaredPermission('tax:quote', 'Get a quote', true),
            new DeclaredPermission('tax:assess', 'Run an assessment', true),
            new DeclaredPermission('support:impersonate', 'Sign in as a customer'),
        ],
        roles: [
            new DeclaredRole('editor', 'Editor', null, ['tax:quote', 'tax:assess']),
            new DeclaredRole('viewer', 'Viewer', null, ['tax:quote']),
            new DeclaredRole('support', 'Support', null, ['support:impersonate'], tenantAssignable: false),
        ],
    ));

    return ['clientId' => $registered->client->client_id, 'secret' => (string) $registered->secret, 'base' => $base];
}

/** @param array{clientId: string, base: string} $world */
function browserChainAuthorize(array $world, array $extra = []): string
{
    return '/oauth/authorize?'.http_build_query([
        'response_type' => 'code',
        'client_id' => $world['clientId'],
        'redirect_uri' => $world['base'].'/cboxtax/callback',
        'scope' => 'openid profile',
        'state' => 'st',
        'code_challenge' => pkcePair(BROWSER_CHAIN_VERIFIER)['challenge'],
        'code_challenge_method' => 'S256',
        ...$extra,
    ]);
}

/**
 * The app's backend redeeming the code the browser arrived with.
 *
 * @param  array{clientId: string, secret: string, base: string}  $world
 * @return array<string, mixed> the access token's verified claims
 */
function browserChainRedeem(array $world, string $arrivedAt): array
{
    expect($arrivedAt)->toStartWith($world['base'].'/cboxtax/callback?');

    parse_str((string) parse_url($arrivedAt, PHP_URL_QUERY), $query);

    expect($query['state'] ?? null)->toBe('st');

    $token = test()->withBasicAuth($world['clientId'], $world['secret'])->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $query['code'] ?? '',
        'redirect_uri' => $world['base'].'/cboxtax/callback',
        'code_verifier' => BROWSER_CHAIN_VERIFIER,
    ])->assertOk()->json('access_token');

    return app(TokenSigner::class)->verify((string) $token, [SigningAlg::RS256])->all();
}

it('walks sign-up, a second team and an invitation back to the app, page by page', function (): void {
    $world = browserChainWorld();

    // ── 1. Anna signs up from the app ───────────────────────────────────────────────
    $anna = visit(browserChainAuthorize($world, ['prompt' => 'create']));

    $anna->assertSee('Create your account')
        ->assertSee('Sign up for cboxtax')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'chain-1-signup');

    $anna->fill('organization', 'Hansen Revision')
        ->fill('name', 'Anna Hansen')
        ->fill('email', 'anna@hansen-revision.test')
        ->fill('password', 'a-strong-unbreached-passphrase')
        ->click('button:has-text("Create account and continue")');

    $anna->assertPathIs('/cboxtax/callback');

    $hansen = Organization::query()->where('name', 'Hansen Revision')->sole();
    $claims = browserChainRedeem($world, $anna->url());

    expect($claims['org'])->toBe($hansen->id)
        ->and($claims['org_role'])->toBe('owner');

    // …and founds a second team from the app's "New team" button.
    $anna->navigate(browserChainAuthorize($world, ['prompt' => 'create_organization']))
        ->assertSee('Create an organization')
        ->assertSee('You will be its owner')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'chain-1-create-organization');

    $anna->fill('name', 'Hansen Holding')
        ->press('Create and continue');

    $anna->assertPathIs('/cboxtax/callback');

    $holding = Organization::query()->where('name', 'Hansen Holding')->sole();
    $claims = browserChainRedeem($world, $anna->url());

    expect($claims['org'])->toBe($holding->id)
        ->and($claims['org_role'])->toBe('owner');

    // ── 2. Anna invites Bo as an Editor, back to the app afterwards ─────────────────
    $anna->navigate('/directory/members')
        ->assertSee('Hansen Revision')
        ->click('Invite member')
        ->assertSee('Invite someone')
        ->assertSee('Editor')
        ->assertSee('Viewer')
        ->assertDontSee('Support')
        ->fill('email', 'bo@nordic-survey.test')
        ->click('label:has-text("Editor")')
        ->click('Send them to an app afterwards…')
        ->click('This console')
        ->click('[role="option"]:has-text("cboxtax")')
        ->fill('return_to', $world['base'].'/cboxtax/welcome')
        ->screenshot(filename: 'chain-2-invite');

    $anna->click('button:has-text("Send invitation")')
        ->assertSee('Invitation sent to bo@nordic-survey.test.')
        ->assertSee('for cboxtax')
        ->assertNoJavaScriptErrors();

    // ── 3. Bo, in his own browser, opens the link and accepts ───────────────────────
    $link = (string) parse_url((string) Mail::sent(InvitationMail::class)->last()?->url, PHP_URL_PATH);

    $bo = visit($link);

    $bo->assertSee('Join Hansen Revision?')
        ->assertSee('Anna Hansen')
        ->assertSee('cboxtax')
        ->assertSee('bo@nordic-survey.test')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'chain-3-join');

    $bo->click('button:has-text("Accept invitation")');

    $bo->assertPathIs('/cboxtax/welcome');

    $bo->navigate(browserChainAuthorize($world, ['scope' => 'openid profile']));
    $bo->assertPathIs('/cboxtax/callback');

    $claims = browserChainRedeem($world, $bo->url());

    expect($claims['org'])->toBe($hansen->id)
        ->and($claims['org_role'])->toBe('member')
        ->and($claims['roles'])->toBe(['editor'])
        ->and($claims['permissions'])->toEqualCanonicalizing(['tax:quote', 'tax:assess']);
});
