<?php

declare(strict_types=1);

use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Support\DeviceApprovalEvidence;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

afterEach(fn () => pest()->browser()->timeout(5000));

it('shows the mobile confirmation and resumes browser login on desktop and mobile', function (int $width): void {
    pest()->browser()->timeout(15000);
    installedDeployment();
    platformRootEnvironment();
    config(['id-devices.enabled' => true]);
    $subject = app(PlatformRoot::class)->run(function (): string {
        [$subject] = accountWithOrg('mobile-browser@example.test');
        app(Subjects::class)->markEmailVerified($subject->id, $subject->email);
        Device::query()->create(['subject_id' => $subject->id, 'install_id' => (string) Str::ulid(),
            'platform' => 'ios', 'name' => 'My phone', 'status' => 'active', 'dpop_jkt' => 'browser-fixture-key']);

        return $subject->id;
    });
    $page = visit('/login')->resize($width, 844);
    $page->fill('email', 'mobile-browser@example.test')->press('Continue')
        ->assertSee('Password')->fill('input[type="password"]', 'supersecret123')
        ->click('button[type="submit"]')->assertSee('Approve on phone')->assertPathIs('/mfa')
        ->click('button:has-text("Approve on phone")')->assertSee('Only approve if this code matches')
        ->assertNoJavaScriptErrors()
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->screenshot(filename: 'mobile-approval-'.$width);
    app(PlatformRoot::class)->run(function () use ($subject): void {
        $approval = BackchannelAuthRequest::query()->where('user_id', $subject)->latest('id')->firstOrFail();
        app(BackchannelAuthentication::class)->approve($approval->id, $subject);
        app(DeviceApprovalEvidence::class)->record($approval->id,
            Introspection::active($subject, 'fixture', [], ['cnf' => ['jkt' => 'browser-fixture-key']]));
    });
    $page->assertSee('Welcome back')->assertPathIs('/dashboard')->assertNoJavaScriptErrors();
})->with([390, 1440]);

it('offers a phone the Authenticator itself and keeps waiting for the answer', function (): void {
    pest()->browser()->timeout(15000);
    installedDeployment();
    platformRootEnvironment();
    config(['id-devices.enabled' => true,
        'id-devices.universal_links.ios_app_ids' => ['7CX7963T36.com.cboxid.authenticator'],
        'id-devices.universal_links.hosts' => ['login.cboxid.test']]);
    app(PlatformRoot::class)->run(function (): void {
        [$subject] = accountWithOrg('phone-link@example.test');
        app(Subjects::class)->markEmailVerified($subject->id, $subject->email);
        Device::query()->create(['subject_id' => $subject->id, 'install_id' => (string) Str::ulid(),
            'platform' => 'ios', 'name' => 'My phone', 'status' => 'active', 'dpop_jkt' => 'browser-link-key']);
    });
    // The headless browser keeps its desktop user agent for the whole run, so the phone it
    // emulates is announced to the server here, the way Safari on an iPhone would.
    app('router')->pushMiddlewareToGroup('web', PretendIphone::class);
    $page = visit('/login')->on()->iPhone15Pro();
    $page->fill('email', 'phone-link@example.test')->press('Continue')
        ->assertSee('Password')->fill('input[type="password"]', 'supersecret123')
        ->click('button[type="submit"]')->assertPathIs('/mfa')
        ->click('button:has-text("Approve on phone")')
        ->assertSee('Open Cbox ID app')
        ->assertSee('This page continues by itself')
        ->assertScript('document.querySelector(\'a[href^="https://login.cboxid.test/authenticator/approve/"]\') !== null', true)
        ->assertNoJavaScriptErrors()
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->screenshot(filename: 'mobile-approval-open-app');
});

final class PretendIphone
{
    public function handle(Request $request, Closure $next): mixed
    {
        $request->headers->set('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.2 Mobile/15E148 Safari/604.1');

        return $next($request);
    }
}
