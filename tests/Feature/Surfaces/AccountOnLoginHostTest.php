<?php

declare(strict_types=1);

use App\Http\Middleware\EnterConsoleThroughStart;
use App\Platform\MailLinks;
use App\Platform\Organizations\OrganizationRequired;
use App\Platform\PlatformAuth;
use App\Platform\Surfaces\ConsoleEntry;
use App\Platform\Surfaces\ReturnUrl;
use App\Platform\Surfaces\SessionSurface;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
/**
 * LOGIN-HOST MODE: a person's own pages live on the login host with the SSO session and
 * are absent on the app host; the console's links to them name the login host; the flows
 * that end "and now the console" enter it through its start; and a mailed link carries
 * the host that serves it, whichever host or worker minted it.
 */

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

const ACCOUNT_LOGIN_HOST = 'login.cboxid.test';
const ACCOUNT_APP_HOST = 'app.cboxid.test';
const ACCOUNT_TENANT_HOST = 'acme.cboxauth.test';

/** Login-host mode, with a default environment and one tenant environment. */
function splitHosts(): Environment
{
    config([
        'cbox-id.tenancy.multi_tenant' => true,
        'cbox-id.tenancy.account_host' => ACCOUNT_APP_HOST,
        'cbox-id.hosts.login' => ACCOUNT_LOGIN_HOST,
        'cbox-id.hosts.api' => 'api.cboxid.test',
        'cbox-id.environments.base_domains' => ['cboxauth.test'],
        'cbox-id.issuer' => 'https://'.ACCOUNT_LOGIN_HOST,
    ]);

    installedDeployment();
    platformRootEnvironment();

    return Environment::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'type' => EnvironmentType::Production,
        'status' => EnvironmentStatus::Active,
        'is_default' => false,
        'settings' => [],
    ]);
}

/** Whether the path exists on the host at all: anything but a 404. */
function accountServed(string $host, string $path, string $method = 'GET'): bool
{
    return test()->call($method, 'https://'.$host.$path)->getStatusCode() !== 404;
}

/**
 * Stand the browser's session on a surface, as the request that minted it there would
 * have ({@see SessionSurface}): the SSO session on the login host, or the console's own.
 */
function sessionOn(string $surface): void
{
    session()->put(SessionSurface::KEY, $surface);
}

/** A person of the default environment, signed in on a surface. */
function splitHostsPerson(string $surface = 'login'): array
{
    $account = provisionAccount();
    signInAsMember($account['subjectId']);
    sessionOn($surface);

    return $account;
}

it('serves each of a person\'s own pages on the login host and none of them on the app host', function (string $path): void {
    splitHosts();
    splitHostsPerson();

    $this->get('https://'.ACCOUNT_LOGIN_HOST.$path)->assertOk();
    $this->get('https://'.ACCOUNT_APP_HOST.$path)->assertNotFound();
})->with([
    'security' => '/settings',
    'sessions and activity' => '/settings/activity',
    'personal API keys' => '/settings/api-keys',
    'connected services' => '/settings/connected-services',
    'the account chooser' => '/settings/accounts',
    'adding an account' => '/settings/accounts/add',
    'the step-up' => '/sudo',
    'the forced password change' => '/password/change',
])->group('security');

it('takes every write on those pages on the login host only', function (string $method, string $path): void {
    splitHosts();
    splitHostsPerson();

    expect(accountServed(ACCOUNT_LOGIN_HOST, $path, $method))->toBeTrue()
        ->and(accountServed(ACCOUNT_APP_HOST, $path, $method))->toBeFalse();
})->with([
    ['PATCH', '/settings/profile'],
    ['POST', '/settings/password'],
    ['POST', '/settings/two-factor/enrol'],
    ['POST', '/settings/two-factor/sms'],
    ['POST', '/settings/sessions/revoke-others'],
    ['POST', '/settings/email/confirmation'],
    ['POST', '/settings/accounts'],
    ['POST', '/passkeys/register/options'],
    ['GET', '/settings/connect/google/redirect'],
    ['POST', '/sudo'],
    ['POST', '/password/change'],
    ['POST', '/organization-required/personal'],
])->group('security');

it('keeps what belongs to the organization in the console, and impersonation off the login host', function (): void {
    splitHosts();
    splitHostsPerson();

    expect(accountServed(ACCOUNT_APP_HOST, '/directory/members'))->toBeTrue()
        ->and(accountServed(ACCOUNT_APP_HOST, '/organization'))->toBeTrue()
        ->and(accountServed(ACCOUNT_APP_HOST, '/approvals'))->toBeTrue()
        ->and(accountServed(ACCOUNT_LOGIN_HOST, '/directory/members'))->toBeFalse()
        ->and(accountServed(ACCOUNT_LOGIN_HOST, '/organization'))->toBeFalse()
        ->and(accountServed(ACCOUNT_LOGIN_HOST, '/settings/organization', 'DELETE'))->toBeFalse()
        // A support session is started, entered and left in the console and nowhere else.
        ->and(accountServed(ACCOUNT_LOGIN_HOST, '/impersonation/launch'))->toBeFalse()
        ->and(accountServed(ACCOUNT_LOGIN_HOST, '/impersonation/exit', 'POST'))->toBeFalse()
        ->and(accountServed(ACCOUNT_LOGIN_HOST, '/support/launch/ses_x'))->toBeFalse();
})->group('security');

it('answers the v2 account addresses on the login host', function (): void {
    splitHosts();

    $this->get('https://'.ACCOUNT_LOGIN_HOST.'/account/api-keys?client_id=abc')
        ->assertRedirect('https://'.ACCOUNT_LOGIN_HOST.'/settings/api-keys?client_id=abc');
    $this->get('https://'.ACCOUNT_APP_HOST.'/account')->assertNotFound();
});

it('draws those pages without the console, with the way back to it', function (): void {
    splitHosts();
    splitHostsPerson();

    $props = $this->get('https://'.ACCOUNT_LOGIN_HOST.'/settings')->assertOk()->inertiaProps();

    expect($props['shell'])->toBeNull()
        ->and($props['apiEquivalents'])->toBe([])
        ->and($props['accountShell']['back'])->toBe(['url' => 'https://'.ACCOUNT_APP_HOST.'/auth/start', 'label' => 'Cbox ID'])
        ->and($props['accountShell']['signOutHref'])->toBe('/logout')
        ->and(array_column($props['accountShell']['pages'], 'href'))->toContain('/settings', '/settings/activity')
        // The chrome carries the way back; the page does not say it a second time.
        ->and($props['returnTo'])->toBeNull();

    // The page that sent them, kept across their pages.
    $from = 'https://'.ACCOUNT_APP_HOST.'/orgs/acme/projects?tab=1';
    $this->get('https://'.ACCOUNT_LOGIN_HOST.'/settings?return_to='.urlencode($from))->assertOk();

    expect($this->get('https://'.ACCOUNT_LOGIN_HOST.'/settings/activity')->inertiaProps('accountShell.back'))
        ->toBe(['url' => $from, 'label' => 'Cbox ID']);

    // An address this deployment does not send people to is not offered.
    $this->get('https://'.ACCOUNT_LOGIN_HOST.'/settings?return_to='.urlencode('https://evil.example/x'))->assertOk();

    expect($this->get('https://'.ACCOUNT_LOGIN_HOST.'/settings')->inertiaProps('accountShell.back.url'))->toBe($from);
});

it('points "organization settings have moved" at the console that serves them, never at the login host', function (): void {
    splitHosts();
    splitHostsPerson();

    $href = $this->get('https://'.ACCOUNT_LOGIN_HOST.'/settings')->assertOk()->inertiaProps('organizationSettingsMoved');

    // Into the console through its start, landing on the organization's page there.
    expect($href)->toBe('https://'.ACCOUNT_APP_HOST.'/auth/start?return_to=%2Forganization');

    $target = (string) parse_url($href, PHP_URL_QUERY);
    parse_str($target, $query);

    expect(parse_url($href, PHP_URL_HOST))->toBe(ACCOUNT_APP_HOST)
        ->and(accountServed(ACCOUNT_APP_HOST, '/auth/start'))->toBeTrue()
        ->and(accountServed(ACCOUNT_APP_HOST, $query['return_to']))->toBeTrue()
        // The address the link used to name.
        ->and(accountServed(ACCOUNT_LOGIN_HOST, '/organization'))->toBeFalse();
})->group('security');

it('sends an old tab\'s organization-settings write on the login host to the console page, not to a 404', function (): void {
    splitHosts();
    splitHostsPerson();

    $this->patch('https://'.ACCOUNT_LOGIN_HOST.'/settings', ['name' => 'Acme'])
        ->assertStatus(303)
        ->assertRedirect('https://'.ACCOUNT_APP_HOST.'/auth/start?return_to=%2Forganization')
        ->assertHeader('Deprecation', 'true')
        ->assertHeader('Link', '<https://'.ACCOUNT_APP_HOST.'/organization>; rel="successor-version"');
})->group('security');

it('names the login host in every console link to a person\'s own page', function (): void {
    splitHosts();
    splitHostsPerson('console');

    $props = $this->get('https://'.ACCOUNT_APP_HOST.'/orgs/acme/projects')->assertOk()->inertiaProps();
    $account = collect($props['shell']['nav']['areas'])->firstWhere('key', 'account');

    expect($props['accountShell'])->toBeNull()
        ->and($account)->not->toBeNull()
        ->and(array_column($account['pages'], 'url'))->each->toStartWith('https://'.ACCOUNT_LOGIN_HOST.'/settings')
        ->and(route('account'))->toBe('https://'.ACCOUNT_LOGIN_HOST.'/settings')
        ->and(route('accounts'))->toBe('https://'.ACCOUNT_LOGIN_HOST.'/settings/accounts')
        ->and(route('account.activity'))->toBe('https://'.ACCOUNT_LOGIN_HOST.'/settings/activity')
        ->and(route('password.change'))->toBe('https://'.ACCOUNT_LOGIN_HOST.'/password/change')
        ->and(route('sudo'))->toBe('https://'.ACCOUNT_LOGIN_HOST.'/sudo')
        ->and(route('login'))->toBe('https://'.ACCOUNT_LOGIN_HOST.'/login')
        // The console's own pages stay where they are, and a shared path on the host asked.
        ->and(route('dashboard'))->toBe('https://'.ACCOUNT_APP_HOST.'/dashboard')
        ->and(route('directory.members'))->toBe('https://'.ACCOUNT_APP_HOST.'/directory/members')
        ->and(route('logout'))->toBe('https://'.ACCOUNT_APP_HOST.'/logout');
});

it('sends an unconfirmed person from the console banner to their account, not to a write the console lacks', function (): void {
    splitHosts();
    platformRootDeployment();
    $subject = app(Subjects::class)->create('new@acme.test', 'New Person', 'a-strong-unbreached-passphrase');
    signInAsSubject($subject->id);
    sessionOn('console');

    $user = $this->get('https://'.ACCOUNT_APP_HOST.'/approvals')->assertOk()->inertiaProps('auth.user');

    expect($user['confirmUrl'])->toBeNull()
        ->and($user['accountUrl'])->toBe('https://'.ACCOUNT_LOGIN_HOST.'/settings');

    // The same person's SSO session, on the login host.
    signInAsSubject($subject->id);
    sessionOn('login');

    expect($this->get('https://'.ACCOUNT_LOGIN_HOST.'/settings')->inertiaProps('auth.user.confirmUrl'))
        ->toBe('/settings/email/confirmation');
});

it('accepts a return address only on a host this deployment sends people to', function (): void {
    splitHosts();
    platformRootDeployment();

    app(ClientRegistry::class)->register(new NewClient(
        name: 'Acme Portal',
        type: ClientType::Confidential,
        redirectUris: ['https://portal.acme.example/auth/callback'],
    ));

    $returns = app(ReturnUrl::class);
    $request = Request::create('https://'.ACCOUNT_LOGIN_HOST.'/settings');
    $valid = static fn (string $url): ?string => $returns->validated($url, $request)['label'] ?? null;

    expect($valid('https://'.ACCOUNT_APP_HOST.'/orgs/acme'))->toBe('Cbox ID')
        ->and($valid('https://'.ACCOUNT_LOGIN_HOST.'/settings'))->toBe('Cbox ID')
        // An app's registered origin, any page on it.
        ->and($valid('https://portal.acme.example/welcome?x=1'))->toBe('Acme Portal')
        ->and($valid('https://evil.example/'))->toBeNull()
        ->and($valid('http://'.ACCOUNT_APP_HOST.'/'))->toBeNull()
        ->and($valid('//'.ACCOUNT_APP_HOST.'/'))->toBeNull()
        ->and($valid('/dashboard'))->toBeNull()
        ->and($valid('javascript:alert(1)'))->toBeNull()
        ->and($valid('https://'.ACCOUNT_APP_HOST.'@evil.example/'))->toBeNull()
        ->and($valid('https://evil.example\\@'.ACCOUNT_APP_HOST.'/'))->toBeNull()
        ->and($valid('https://'.ACCOUNT_APP_HOST.'.evil.example/'))->toBeNull()
        ->and($valid("https://evil.example/\n".ACCOUNT_APP_HOST))->toBeNull()
        ->and($valid('https://'.ACCOUNT_APP_HOST.'/'.str_repeat('a', 2100)))->toBeNull()
        ->and($returns->validated(['https://'.ACCOUNT_APP_HOST], $request))->toBeNull();
})->group('security');

it('switches account on the login host and has the console ask who is active', function (): void {
    splitHosts();
    $first = provisionAccount('owner@acme.example');
    platformRootDeployment();
    $second = app(Subjects::class)->create('second@acme.test', 'Second', 'a-strong-unbreached-passphrase');

    signInAsSubject($first['subjectId']);
    signInAsSubject($second->id);
    sessionOn('login');

    $switch = fn (string $subject) => $this->post('https://'.ACCOUNT_LOGIN_HOST.'/settings/accounts', ['subject' => $subject]);

    // No page named: the console's own landing.
    $switch($first['subjectId'])->assertRedirect('https://'.ACCOUNT_APP_HOST.'/auth/start?return_to=%2Fdashboard');
    expect(session(PlatformAuth::ACTIVE_KEY))->toBe($first['subjectId']);

    // Opened from a console page: back to that page, through the start.
    $this->get('https://'.ACCOUNT_LOGIN_HOST.'/settings/accounts?return_to='.urlencode('https://'.ACCOUNT_APP_HOST.'/orgs/acme/projects?tab=1'))->assertOk();
    $switch($second->id)->assertRedirect('https://'.ACCOUNT_APP_HOST.'/auth/start?return_to='.urlencode('/orgs/acme/projects?tab=1'));
    expect(session(PlatformAuth::ACTIVE_KEY))->toBe($second->id);

    // An address that is nobody's is not followed.
    $this->get('https://'.ACCOUNT_LOGIN_HOST.'/settings/accounts?return_to='.urlencode('https://evil.example/x'))->assertOk();
    $switch($first['subjectId'])->assertRedirect('https://'.ACCOUNT_APP_HOST.'/auth/start?return_to=%2Fdashboard');

    // The chooser is not the console's to serve.
    $this->post('https://'.ACCOUNT_APP_HOST.'/settings/accounts', ['subject' => $second->id])->assertNotFound();
})->group('security');

it('enters the console through its start from the login host, and leaves protocol redirects alone', function (): void {
    splitHosts();
    $entry = app(ConsoleEntry::class);
    $app = 'https://'.ACCOUNT_APP_HOST;

    $this->get('https://'.ACCOUNT_LOGIN_HOST.'/login');

    expect($entry->through($app.'/dashboard'))->toBe($app.'/auth/start?return_to=%2Fdashboard')
        ->and($entry->through($app.'/orgs/acme?x=1'))->toBe($app.'/auth/start?return_to='.urlencode('/orgs/acme?x=1'))
        ->and($entry->through($app))->toBe($app.'/auth/start')
        // The start and its callback are not wrapped again; other hosts are not the console.
        ->and($entry->through($app.'/auth/start?return_to=%2Fx'))->toBeNull()
        ->and($entry->through($app.'/auth/callback?code=abc'))->toBeNull()
        ->and($entry->through('https://'.ACCOUNT_LOGIN_HOST.'/settings'))->toBeNull()
        ->and($entry->through($app.'.evil.example/dashboard'))->toBeNull()
        ->and($entry->url('//evil.example'))->toBe($app.'/auth/start');

    $run = static fn (string $uri, mixed $response): mixed => app(EnterConsoleThroughStart::class)
        ->handle(Request::create($uri, 'POST'), static fn () => $response);

    expect($run('https://'.ACCOUNT_LOGIN_HOST.'/login', redirect()->to($app.'/dashboard'))->getTargetUrl())
        ->toBe($app.'/auth/start?return_to=%2Fdashboard')
        ->and($run('https://'.ACCOUNT_LOGIN_HOST.'/passkeys/login', new JsonResponse(['redirect' => $app.'/dashboard']))->getData(true))
        ->toBe(['redirect' => $app.'/auth/start?return_to=%2Fdashboard'])
        // A client on the app host is answered at the redirect URI it registered.
        ->and($run('https://'.ACCOUNT_LOGIN_HOST.'/oauth/authorize', redirect()->to($app.'/some/callback?code=abc'))->getTargetUrl())
        ->toBe($app.'/some/callback?code=abc')
        // The console's own redirects are its own.
        ->and($run($app.'/orgs', redirect()->to($app.'/dashboard'))->getTargetUrl())->toBe($app.'/dashboard');

    // End to end: somebody signed in who opens the sign-in page is on their way to the console.
    splitHostsPerson();
    $this->get('https://'.ACCOUNT_LOGIN_HOST.'/login')->assertRedirect($app.'/auth/start?return_to=%2Fdashboard');
});

it('sends a person with no organization from the console to the login host and back', function (): void {
    splitHosts();
    config(['cbox-id.signup.personal_organization' => true]);
    platformRootDeployment();

    $subject = app(Subjects::class)->create('pat@acme.test', 'Pat Doe', 'a-strong-unbreached-passphrase');
    app(Subjects::class)->markEmailVerified($subject->id, 'pat@acme.test');
    signInAsSubject($subject->id);
    sessionOn('console');

    $page = 'https://'.ACCOUNT_APP_HOST.'/dashboard';
    $required = 'https://'.ACCOUNT_LOGIN_HOST.'/organization-required?return_to='.urlencode($page);

    $this->get($page)->assertRedirect($required);

    // The login host has the person's SSO session, not the console's.
    signInAsSubject($subject->id);
    sessionOn('login');

    $this->get($required)->assertOk();

    $this->post('https://'.ACCOUNT_LOGIN_HOST.'/organization-required/personal')
        ->assertRedirect('https://'.ACCOUNT_APP_HOST.'/auth/start?return_to=%2Fdashboard');

    expect(app(OrganizationRequired::class)->url())->toStartWith('https://'.ACCOUNT_LOGIN_HOST.'/organization-required');
});

it('mails each link on the host that serves it, whichever host or worker minted it', function (string $minter): void {
    $tenant = splitHosts();
    platformRootDeployment();
    config(['app.url' => 'https://worker.internal']);

    // A queue worker has no request host: the generator stands on `app.url`.
    app()->instance('request', Request::create($minter));
    URL::setRequest(request());

    $links = app(MailLinks::class);
    $login = 'https://'.ACCOUNT_LOGIN_HOST;

    expect($links->route('password.reset', 'tok'))->toBe($login.'/reset-password/tok')
        ->and($links->route('magic.redeem', 'tok'))->toBe($login.'/magic/tok')
        ->and($links->route('verification.verify', 'tok'))->toBe($login.'/verify-email/tok')
        ->and($links->route('invitation.accept', 'tok'))->toBe($login.'/invitations/tok/accept')
        ->and($links->route('portal.enter', 'tok'))->toBe($login.'/setup/tok')
        ->and($links->temporarySignedRoute('organization.invite.accept', 600, ['token' => 'tok']))->toStartWith($login.'/invite/tok/accept?')
        ->and($links->to('/brand-assets/logo.png'))->toBe($login.'/brand-assets/logo.png')
        // A console link is the app host's.
        ->and($links->route('approvals'))->toBe('https://'.ACCOUNT_APP_HOST.'/approvals')
        ->and($links->route('dashboard'))->toBe('https://'.ACCOUNT_APP_HOST.'/dashboard');

    // The signature is over the login host, so the link opens there and nowhere else.
    $signed = $links->temporarySignedRoute('organization.invite.accept', 600, ['token' => 'tok']);

    expect(URL::hasValidSignature(Request::create($signed)))->toBeTrue()
        ->and(URL::hasValidSignature(Request::create(str_replace(ACCOUNT_LOGIN_HOST, ACCOUNT_APP_HOST, $signed))))->toBeFalse();

    // Acting in a tenant's environment: its links are on its own host.
    app(EnvironmentContext::class)->set($tenant);

    expect($links->route('password.reset', 'tok'))->toBe('https://'.ACCOUNT_TENANT_HOST.'/reset-password/tok')
        ->and($links->route('invitation.accept', 'tok'))->toBe('https://'.ACCOUNT_TENANT_HOST.'/invitations/tok/accept')
        ->and($links->route('portal.enter', 'tok'))->toBe('https://'.ACCOUNT_TENANT_HOST.'/setup/tok');
})->with([
    'a queue worker' => 'https://worker.internal',
    'the console' => 'https://'.ACCOUNT_APP_HOST.'/directory/members',
    'the login host' => 'https://'.ACCOUNT_LOGIN_HOST.'/forgot-password',
    'a host nobody configured' => 'https://evil.example/forgot-password',
])->group('security');

it('changes nothing about links or chrome while no login host is configured', function (): void {
    installedDeployment();
    splitHostsPerson();

    $props = $this->get('/settings')->assertOk()->inertiaProps();

    expect($props['accountShell'])->toBeNull()
        ->and($props['shell'])->not->toBeNull()
        ->and(route('account'))->toBe(url('/settings'))
        ->and(route('dashboard'))->toBe(url('/dashboard'))
        ->and(app(ConsoleEntry::class)->through(url('/dashboard')))->toBeNull();

    $this->get('/login')->assertRedirect(url('/dashboard'));
});
