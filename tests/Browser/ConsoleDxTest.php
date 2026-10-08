<?php

declare(strict_types=1);

use App\Platform\EnvironmentSudo;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Models\AccessToken;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Support\Str;

/**
 * ⌘K AND GET STARTED, DRAWN.
 *
 * The feature suite proves what the search finds and what the quickstart creates. It cannot
 * see that ⌘K opens the palette, that typing a pasted id highlights the record so Enter
 * opens it, or that the quickstart's snippet and its "waiting" state are actually on screen
 * and turn into "It works" once somebody signs in — so those are driven here.
 */
beforeEach(function (): void {
    installedDeployment();
});

it('opens the palette on ⌘K, finds a user by a pasted id and jumps to them', function (): void {
    actAsEnvironmentAdminOfATenant();

    $user = app(Subjects::class)->create('grace@hopper.test', 'Grace Hopper', 'supersecret123');

    $page = visit('/admin');

    $page->keys('.cbx-page-title', 'Meta+k')
        ->assertVisible('[cmdk-input]')
        ->assertSee('Applications')
        ->type('[cmdk-input]', $user->id)
        ->assertSee('Open Grace Hopper')
        ->keys('[cmdk-input]', 'Enter')
        ->assertPathIs('/admin/users/'.$user->id)
        ->assertSee('Grace Hopper')
        ->assertNoJavaScriptErrors();
})->group('a11y');

it('runs Get started from a framework to the first sign-in', function (): void {
    actAsEnvironmentAdminOfATenant();
    app(EnvironmentSudo::class)->confirm();

    $page = visit('/admin/get-started');

    $page->assertSee('What are you building?')
        ->assertSee('Create your first app')
        ->click('label.cbx-radio:has-text("Laravel")')
        ->fill('name', 'Ledger')
        ->click('button:has-text("Create the app")')
        ->assertSee('Wire it up')
        ->assertSee('composer require cboxdk/laravel-id-client')
        ->assertSee('CBOX_ID_CLIENT_SECRET=csec_')
        ->assertSee('Waiting for your first sign-in')
        ->assertNoJavaScriptErrors()
        // The "created" toast slides in with an opacity animation; axe measuring it mid-fade
        // reads a half-transparent foreground as low contrast. Checked once it has landed.
        ->wait(1)
        ->assertNoAccessibilityIssues();

    // Somebody signs in to the app the quickstart made; the page notices on its own.
    $app = Client::query()->where('name', 'Ledger')->sole();
    $person = app(Subjects::class)->create('first@ledger.test', 'First Person', 'supersecret123');

    AccessToken::query()->create([
        'jti' => (string) Str::ulid(),
        'client_id' => $app->client_id,
        'user_id' => $person->id,
        'scopes' => ['openid'],
        'expires_at' => now()->addHour(),
    ]);

    $page->assertSee('It works.')
        ->assertNoJavaScriptErrors();
})->group('a11y');

it('shows the API twin of a form and fits Get started on a phone', function (): void {
    actAsEnvironmentAdminOfATenant();
    app(EnvironmentSudo::class)->confirm();

    visit('/admin/apps/new')
        ->fill('name', 'Billing')
        ->click('[data-api-equivalent="apps.create"] button')
        ->assertSee('"name": "Billing"')
        ->assertSee('apps:write')
        ->assertNoJavaScriptErrors();

    visit('/admin/get-started')->resize(375, 812)
        ->assertSee('What are you building?')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();
});
