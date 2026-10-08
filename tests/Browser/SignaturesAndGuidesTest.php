<?php

declare(strict_types=1);

use App\Platform\AdminPortal;
use App\Platform\Enums\PortalIntent;
use App\Platform\Enums\PortalScope;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Webhooks\Enums\SignatureScheme;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;

/*
|--------------------------------------------------------------------------
| The two new screens laravel-id 1.23 brought, in a real browser.
|--------------------------------------------------------------------------
|
| A webhook endpoint's signature scheme — chosen on the form, shown on the endpoint, changed
| behind a typed confirmation that says no new secret is issued — and the Admin Portal's
| guides for the identity providers the framework added, with their step text in the
| visitor's language and their field labels left as the provider prints them.
*/

beforeEach(function (): void {
    installedDeployment();
    config(['cbox-id.webhooks.verify_url' => false]);
});

function anOwnerChoosingSchemes(): void
{
    platformRootEnvironment();

    $subject = app(PlatformRoot::class)->run(function () {
        $subject = app(Subjects::class)->create('schemes@acme.test', 'Owner', 'a-strong-unbreached-passphrase');
        app(Subjects::class)->markEmailVerified($subject->id, 'schemes@acme.test');

        $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-schemes'));
        app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
        app(Projects::class)->createForOrganization($org->id, 'Acme');

        return $subject;
    });

    signInAsMember($subject->id);
    app(Sudo::class)->confirm();
}

it('registers a Standard Webhooks endpoint and moves it back to Cbox behind a typed confirmation', function (): void {
    anOwnerChoosingSchemes();

    $page = visit('/webhooks/new');

    $page->assertSee('Signature scheme')
        ->fill('input[type="url"]', 'https://hooks.example.test/in')
        ->click('label:has-text("Standard Webhooks")')
        ->click('fieldset label >> nth=0')
        ->click('button[type="submit"]');

    // The secret is a whsec_ secret, ready for a Standard Webhooks library as it is.
    $page->assertSee('Copy this signing secret now')
        ->assertSee('whsec_')
        ->assertSee('Standard Webhooks')
        ->assertNoJavaScriptErrors();

    $page->click('label:has-text("Cbox")')
        ->assertSee('used exactly as written')
        ->click('button:has-text("Change scheme")')
        ->assertSee('No new secret is issued')
        ->fill('.cbx-dialog input.input', 'https://hooks.example.test/in')
        ->click('.cbx-dialog button:has-text("Change scheme")')
        ->assertSee('Signature scheme changed')
        ->assertNoJavaScriptErrors();

    expect(WebhookEndpoint::query()->where('url', 'https://hooks.example.test/in')->value('signature_scheme'))->toBe(SignatureScheme::Cbox);
});

it('guides an IT administrator through AD FS with AD FS\'s own labels', function (): void {
    $org = app(Organizations::class)->create(new NewOrganization('Acme Guides', 'acme-guides-browser'))->id;
    $token = app(AdminPortal::class)->generate($org, PortalScope::only(PortalIntent::Sso), 'sub_minter');

    $page = visit('/setup/'.$token);
    $page->click('button:has-text("Open setup")');

    $page = visit('/setup/single-sign-on')
        ->assertSee('AD FS')
        ->assertSee('Cloudflare Access')
        ->assertSee('Oracle Cloud Infrastructure IAM');

    $page->click('a:has-text("AD FS")')
        ->click('button[type="submit"]')
        ->assertSee('Add Relying Party Trust')
        ->assertSee('federationmetadata.xml')
        ->assertNoJavaScriptErrors();
});
