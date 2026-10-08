<?php

declare(strict_types=1);

use App\Platform\AdminPortal;
use App\Platform\Enums\PortalIntent;
use App\Platform\Enums\PortalScope;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Federation\Testing\InteractsWithFederation;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;

/*
|--------------------------------------------------------------------------
| THE ADMIN PORTAL, WALKED IN A REAL BROWSER — as the customer's IT administrator walks it.
|--------------------------------------------------------------------------
|
| Open the link, land on the checklist, prove a domain: add it, publish the TXT record the
| page shows, check it, see it verified — with axe run over each screen the person sees, in
| both themes' worth of contrast the design system tunes for, and again at a phone's width,
| where the record's long value is the thing most likely to push the page sideways.
*/

uses(InteractsWithFederation::class);

beforeEach(function (): void {
    installedDeployment();
});

it('walks domain verification in the portal, accessibly, on a desktop and on a phone', function (): void {
    $dns = $this->fakeDns();
    $org = app(Organizations::class)->create(new NewOrganization('Acme Portal', 'acme-portal-browser'))->id;
    $token = app(AdminPortal::class)->generate($org, PortalScope::of([PortalIntent::DomainVerification, PortalIntent::LogStreams]), 'sub_minter');

    // The link's own page renders a button; only the click spends it.
    $page = visit('/setup/'.$token)
        ->assertSee('Open setup')
        ->assertNoAccessibilityIssues();

    $page->click('button:has-text("Open setup")')
        ->assertSee('Set up Acme Portal')
        ->assertSee('Domain verification')
        ->assertSee('Log streams')
        ->assertNoAccessibilityIssues();

    $page->click('a[aria-label="Domain verification: Start"]')
        ->assertSee('Verify your domains')
        ->fill('domain', 'acme.com')
        ->click('button:has-text("Add domain")')
        ->assertSee('Waiting for DNS')
        ->assertSee('TXT')
        ->assertNoAccessibilityIssues();

    $domain = VerifiedDomain::query()->where('organization_id', $org)->sole();
    $dns->publish(app(DomainVerification::class)->challengeHost('acme.com'), $domain->verification_token);

    // The record's value is on the page to copy, in full.
    $page->assertSee($domain->verification_token)
        ->click('button[aria-label="Check DNS for acme.com"]')
        ->assertSee('Domain verified.')
        ->assertDontSee('Waiting for DNS')
        ->assertNoAccessibilityIssues();

    expect($domain->refresh()->isVerified())->toBeTrue();

    // On a phone: nothing pushes the page sideways, and it is still clean.
    $page->resize(375, 812)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoAccessibilityIssues();

    $page->click('a:has-text("All setup tasks")')
        ->assertSee('2 of 2 steps done')
        ->assertSee('0 of 2 steps done')
        ->assertNoAccessibilityIssues();
});
