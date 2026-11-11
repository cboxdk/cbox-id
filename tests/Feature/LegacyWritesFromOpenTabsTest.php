<?php

declare(strict_types=1);

use App\Platform\Console\EnvironmentConsolePath;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/*
|--------------------------------------------------------------------------
| A v2 tab left open still writes.
|--------------------------------------------------------------------------
|
| ID is live, so a console tab opened before v3 can send its PATCH, PUT, POST or DELETE to
| an address that moved. A 301 or 302 turns that write into a GET in the browser (Inertia's
| XHR follows it), and the change is lost without a word. So every moved write answers a
| 307 or 308, which keeps the method and the body, and is sent here as Inertia sends it.
|
| Across hosts (a tenant host's old `/admin/…`), a browser will not carry a cross-origin XHR
| through a redirect, so the tab is sent to the page on the new address instead: Inertia's
| 409 with `X-Inertia-Location`, never a 405 or a silent GET.
*/

/** A write as an old tab's Inertia client sends it. */
function oldTabWrite(string $method, string $url, array $data = []): TestResponse
{
    return test()->call($method, $url, $data, [], [], [
        'HTTP_X_INERTIA' => 'true',
        'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        'HTTP_ACCEPT' => 'text/html, application/xhtml+xml',
    ]);
}

/** Follow one 307/308 the way a browser does: the same method and body, to the Location. */
function followKeepingMethod(TestResponse $redirect, string $method, array $data = []): TestResponse
{
    expect($redirect->status())->toBeIn([307, 308]);

    return oldTabWrite($method, (string) $redirect->headers->get('Location'), $data);
}

it('carries an old tab\'s organization-settings PATCH through to the organization', function (): void {
    ['organization' => $organization, 'subjectId' => $subjectId] = provisionAccount();
    signInAsMember($subjectId);

    $moved = oldTabWrite('PATCH', '/workspace-settings', ['name' => 'Renamed in an old tab']);
    $moved->assertStatus(307)->assertRedirect('/orgs/'.orgSlug($organization->id).'/settings');

    followKeepingMethod($moved, 'PATCH', ['name' => 'Renamed in an old tab'])->assertSessionHasNoErrors();

    expect(app(PlatformRoot::class)->run(fn () => app(Organizations::class)->find($organization->id))->name)
        ->toBe('Renamed in an old tab');
});

it('carries an old tab\'s profile PATCH and its DELETE on to /settings', function (): void {
    ['subjectId' => $subjectId] = provisionAccount();
    signInAsMember($subjectId);

    $moved = oldTabWrite('PATCH', '/account/profile', ['displayName' => 'Ada from an old tab']);
    $moved->assertStatus(308)->assertRedirect('/settings/profile');
    followKeepingMethod($moved, 'PATCH', ['displayName' => 'Ada from an old tab'])->assertSessionHasNoErrors();
    expect(app(PlatformRoot::class)->run(fn () => app(Subjects::class)->find($subjectId))->name)->toBe('Ada from an old tab');

    oldTabWrite('DELETE', '/account/api-keys/01hzzzzzzzzzzzzzzzzzzzzzzz')
        ->assertStatus(308)
        ->assertRedirect('/settings/api-keys/01hzzzzzzzzzzzzzzzzzzzzzzz');
});

it('keeps an old tab\'s platform-admin writes POSTs, at their new address', function (): void {
    multiTenantDeployment();
    config()->set('cbox-id.environments.base_domains', ['cboxid.com']);
    actAsOperator();

    $moved = oldTabWrite('POST', 'https://cboxid.com/admin/platform/workspaces', ['name' => 'Made in an old tab']);
    $moved->assertStatus(308)
        ->assertHeader('Deprecation', 'true')
        ->assertRedirect('https://cboxid.com/admin/organizations');

    expect(followKeepingMethod($moved, 'POST', ['name' => 'Made in an old tab'])->status())->not->toBeIn([404, 405]);

    oldTabWrite('POST', 'https://cboxid.com/admin/platform/environment/switch', ['environment' => 'x'])
        ->assertStatus(308)
        ->assertRedirect('https://cboxid.com/admin/environments/switch');
    oldTabWrite('POST', 'https://cboxid.com/admin/platform/workspaces/01hzzzzzzzzzzzzzzzzzzzzzzz/status')
        ->assertStatus(308)
        ->assertRedirect('https://cboxid.com/admin/organizations/01hzzzzzzzzzzzzzzzzzzzzzzz/status');

    // A read stays a counted 302.
    $this->get('https://cboxid.com/admin/platform/workspaces')->assertStatus(302);
})->group('security');

it('sends an old tab\'s write on a tenant host\'s /admin to that page of the console, never a GET or a 405', function (): void {
    multiTenantDeployment();
    config()->set('cbox-id.environments.base_domains', ['cboxid.com']);
    platformRootEnvironment();
    $provisioned = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));
    $host = 'https://'.$provisioned->environment->slug.'.cboxid.com';
    $console = 'https://cboxid.com/'.app(EnvironmentConsolePath::class)->for($provisioned->environment->id);

    foreach ([
        ['PATCH', '/admin/users/01hzzzzzzzzzzzzzzzzzzzzzzz', '/users/01hzzzzzzzzzzzzzzzzzzzzzzz'],
        // A sub-resource with no page of its own lands on the nearest page that has one.
        ['DELETE', '/admin/users/01hzzzzzzzzzzzzzzzzzzzzzzz/sessions/01hzzzzzzzzzzzzzzzzzzzzzzz', '/users/01hzzzzzzzzzzzzzzzzzzzzzzz'],
        // A path platform admin owns now was an environment-console page on this host.
        ['POST', '/admin/organizations', '/organizations'],
        ['PUT', '/admin/nothing-here/at-all', ''],
    ] as [$method, $path, $page]) {
        oldTabWrite($method, $host.$path, ['name' => 'x'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', $console.$page)
            ->assertHeader('Deprecation', 'true');
    }

    // Nothing but the console's own (Inertia) tab ever wrote there: anything else is the 404 it was.
    $this->patch($host.'/admin/users/01hzzzzzzzzzzzzzzzzzzzzzzz', ['name' => 'x'])->assertNotFound();
    $this->post($host.'/admin/organizations', ['name' => 'Nope'])->assertNotFound();
})->group('security');
