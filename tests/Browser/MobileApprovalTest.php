<?php

declare(strict_types=1);

use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Support\DeviceApprovalEvidence;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\Platform\PlatformRoot;
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
