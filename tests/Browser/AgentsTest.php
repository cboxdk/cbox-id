<?php

declare(strict_types=1);

use App\Platform\EnvironmentSudo;
use Cbox\Id\Platform\Models\EnvironmentApiKey;

/**
 * AI AGENTS, DRAWN.
 *
 * The feature suite proves what the pages are given and what the key actions refuse. It
 * cannot see whether choosing a preset ticks the right boxes, whether the key and the
 * command carrying it are actually on screen after Create, or whether the key is gone
 * again once the person moves on — so the create flow is driven here, end to end.
 */
beforeEach(function (): void {
    installedDeployment();
});

it('creates an agent key from a preset and shows the key once, with the command to use it', function (): void {
    actAsEnvironmentAdminOfATenant();
    app(EnvironmentSudo::class)->confirm();

    $page = visit('/admin/agents/new');

    $page->assertSee('New agent')
        ->assertSee('Support agent')
        ->assertSee('Full admin')
        ->fill('name', 'Claude Code')
        ->click('button:has-text("Support agent")')
        // The preset ticked the people writes and held destruction for approval.
        ->assertScript('Array.from(document.querySelectorAll("fieldset button[aria-pressed=true]")).map(b => b.textContent).join(" ").includes("Support agent")', true)
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues();

    $page->click('button:has-text("Create agent key")')
        ->assertSee('Claude Code is ready')
        ->assertSee('Copy your key now')
        ->assertSee('Connect Claude Code')
        ->assertSee('claude mcp add --transport http cbox-id')
        ->assertNoJavaScriptErrors();

    $key = EnvironmentApiKey::query()->where('name', 'Claude Code')->sole();

    expect($key->scopes)->toContain('users:write')
        ->and($key->scopes)->not->toContain('apps:write')
        ->and($key->step_up_policy)->toEqual(['min_danger' => 'destructive', 'actions' => []]);

    $value = (string) $page->script('document.querySelector("code.select-all")?.textContent ?? ""');

    expect($value)->toStartWith($key->prefix);

    // Once: the list it leads back to names the agent and never shows the value again.
    $page->click('a:has-text("Done")')
        ->assertSee('Claude Code')
        ->assertSee('Approval for destructive and critical actions')
        ->assertDontSee($value)
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues();
})->group('a11y');

it('draws Connect with a snippet for each client and no JavaScript errors', function (): void {
    actAsEnvironmentAdminOfATenant();

    $page = visit('/admin/agents/connect');

    $page->assertSee('Connect')
        ->assertSee('claude mcp add --transport http cbox-id')
        ->assertSee('Or sign in with an account of this environment')
        ->click('button[role=tab]:has-text("Cursor")')
        ->assertSee('.cursor/mcp.json')
        ->click('button[role=tab]:has-text("VS Code")')
        ->assertSee('promptString')
        ->click('button[role=tab]:has-text("Generic")')
        ->assertSee('oauth-protected-resource/mcp')
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues();
})->group('a11y');

it('fits the agents pages on a phone', function (): void {
    actAsEnvironmentAdminOfATenant();
    app(EnvironmentSudo::class)->confirm();

    foreach (['/admin/agents', '/admin/agents/new', '/admin/agents/connect', '/admin/approvals'] as $path) {
        visit($path)
            ->resize(375, 812)
            ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
            ->assertNoJavaScriptErrors();
    }
})->group('a11y');
