<?php

declare(strict_types=1);

use App\Mail\InvitationMail;
use App\Platform\NativeProduct\NativeEnvironmentAccess;
use App\Platform\NativeProduct\NativeProductMembers;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Mail;

it('edits multiple native roles and invites with app roles on desktop and mobile', function (): void {
    Mail::fake();
    installedDeployment();
    $account = provisionAccount();
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($account['subjectId'], 'owner@acme.example'));
    [$member] = addMember($account['organization']->id, MembershipRole::Member, 'native-colleague@example.test', 'Colleague');
    grantNativeProductRoles($member, ['viewer']);
    signInAsMember($account['subjectId']);

    $page = visit('/members?organization='.$account['organization']->id);
    $page->assertSee('Colleague')->assertSee('Owner')->click('details > summary')
        ->check('details label:has-text("Billing") input')->click('details button:has-text("Save")')
        ->assertSee('Reader, Billing')->assertNoJavaScriptErrors()
        ->screenshot(filename: 'native-members-desktop');
    expect(app(NativeProductMembers::class)->access($member)['roles'])->toEqualCanonicalizing(['viewer', 'billing']);

    $page->click('[aria-label="More actions for native-colleague@example.test"]')->click('Manage environment access')
        ->assertScript('new URL(location.href).searchParams.get("organization")', $account['organization']->id)
        ->click('label:has-text("All environments (including ones added later)")')->click('Save access')
        ->assertSee('Access to 1 of 1 environments');

    // Crossing to the phone layout remounts the page (cbox-ui 0.11 draws its own phone tree): wait for it.
    $page->resize(390, 844)->wait(0.5)->fill('form:has(button:has-text("Send invitation")) input[type="email"]', 'native-new@example.test')
        ->check('form label:has-text("Billing") input')->click('button:has-text("Send invitation")')
        ->assertSee('native-new@example.test')->assertSee('Billing, Reader')
        ->assertNoJavaScriptErrors()->screenshot(filename: 'native-members-phone');
    Mail::assertQueued(InvitationMail::class, 1);
    $artifacts = getenv('NATIVE_BROWSER_ARTIFACTS');
    if (is_string($artifacts) && is_dir($artifacts)) {
        foreach (['desktop', 'phone'] as $size) {
            copy(base_path('tests/Browser/Screenshots/native-members-'.$size.'.png'), $artifacts.'/native-members-'.$size.'.png');
        }
    }
});

it('lets a native Reader inspect a user with visibly disabled write controls', function (): void {
    installedDeployment();
    $owner = crudSetup();
    $user = app(Subjects::class)->create('reader-visible@example.test', 'Visible Person');
    [$reader, $subject] = addMember($owner['member']->organization_id, MembershipRole::Member, 'browser-reader@example.test');
    grantNativeProductRoles($reader, ['viewer']);
    actAsEnvironmentAdmin($subject, $owner['envId']);

    $page = visit(envConsole('/users/').$user->id);
    $page->assertSee('Visible Person')->assertSee('Read-only')
        ->assertScript('document.querySelectorAll("fieldset:disabled").length > 0', true)
        ->assertScript('Array.from(document.querySelectorAll("form input, form button")).every(control => control.matches(":disabled"))', true)
        ->assertNoJavaScriptErrors();
});

it('refuses an outdated role form without overwriting a newer assignment', function (): void {
    installedDeployment();
    $account = provisionAccount();
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($account['subjectId'], 'owner@acme.example'));
    [$member] = addMember($account['organization']->id, MembershipRole::Member, 'stale-colleague@example.test', 'Colleague');
    grantNativeProductRoles($member, ['viewer']);
    signInAsMember($account['subjectId']);
    $page = visit('/members?organization='.$account['organization']->id);
    $page->click('details > summary')->check('details label:has-text("Billing") input');
    // Another administrator changes this member after the form was opened.
    grantNativeProductRoles($member, ['developer']);
    $page->click('details button:has-text("Save")')
        ->assertSee("This member's roles changed. Reload their access before saving.")
        ->assertScript('Array.from(document.querySelectorAll("details label")).find(label => label.textContent.trim() === "Editor").querySelector("input").checked', true)
        ->assertNoJavaScriptErrors();
    expect(app(NativeProductMembers::class)->access($member)['roles'])->toBe(['developer']);
});

it('refuses an outdated environment selection independently of app roles', function (): void {
    installedDeployment();
    $account = provisionAccount();
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($account['subjectId'], 'owner@acme.example'));
    [$member] = addMember($account['organization']->id, MembershipRole::Member, 'row-colleague@example.test', 'Colleague');
    grantNativeProductRoles($member, ['viewer']);
    signInAsMember($account['subjectId']);
    $page = visit('/members?organization='.$account['organization']->id);
    $page->click('[aria-label="More actions for row-colleague@example.test"]')->click('Manage environment access')
        ->assertScript('new URL(location.href).searchParams.get("organization")', $account['organization']->id)
        ->click('label:has-text("All environments (including ones added later)")');
    $rows = app(NativeEnvironmentAccess::class);
    $rows->replace($member, false, [], $rows->version($member));
    $page->click('button:has-text("Save access")')
        ->assertSee('This member’s environment access changed. Reload it before saving.')
        ->assertNoJavaScriptErrors();
    expect($rows->ids($account['organization']->id, $member->user_id))->toBe([])
        ->and(app(NativeProductMembers::class)->access($member)['roles'])->toBe(['viewer']);
});

it('removes app access from the member page while keeping the shared membership', function (): void {
    installedDeployment();
    $account = provisionAccount();
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($account['subjectId'], 'owner@acme.example'));
    [$member] = addMember($account['organization']->id, MembershipRole::Member, 'remove-colleague@example.test', 'Colleague');
    grantNativeProductRoles($member, ['viewer', 'billing']);
    signInAsMember($account['subjectId']);
    $page = visit('/members?organization='.$account['organization']->id);
    $page->click('[aria-label="More actions for remove-colleague@example.test"]')->click('[role="menuitem"]:has-text("Remove")')
        ->fill('.cbx-dialog input.input', 'remove-colleague@example.test')
        ->click('.cbx-dialog button:has-text("Remove")')
        ->assertSee('Colleague')->assertNoJavaScriptErrors();
    expect(app(NativeProductMembers::class)->access($member)['roles'])->toBe([]);
    expect(app(PlatformRoot::class)->run(fn () => app(Memberships::class)->of($member->organization_id, $member->user_id)))->not->toBeNull();
});
