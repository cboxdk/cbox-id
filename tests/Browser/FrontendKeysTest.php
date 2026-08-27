<?php

declare(strict_types=1);

use Cbox\Id\FrontendApi\Contracts\PublishableKeys;
use Cbox\Id\FrontendApi\Enums\KeyMode;

/**
 * THE CREATE FORM MUST NOT CONTAIN THE KEY LIST.
 *
 * It used to. The list rendered inside the `<form>` element, so a browser treated every
 * control on every existing key as part of that submission: Enter anywhere on the page
 * reached the create handler, and the form's own reset cleared inputs belonging to other
 * keys' allow-lists.
 *
 * That is a fact about the DOM, so the only place it can be checked is a browser. The
 * feature suite asserts what the page is GIVEN; this asserts the shape it builds.
 */
beforeEach(function (): void {
    installedDeployment();
});

it('keeps the key list outside the create form', function (): void {
    $environmentId = actAsEnvironmentAdminOfATenant();

    app(PublishableKeys::class)->issue('Site', KeyMode::Test, ['https://acme.test']);

    $page = visit('/admin/frontend-keys');

    $page->assertSee('Frontend keys')
        ->assertSee('Site')
        ->press('New key')
        ->assertSee('Create key');

    /*
     * NO NESTED FORMS AT ALL. Each key's allow-list editor is a form of its own, and the
     * create panel is another — nesting any of them is invalid HTML that browsers
     * "recover" from by silently dropping the inner one, which is how a Save button comes
     * to submit somebody else's fields.
     */
    $page->assertScript('document.querySelectorAll("form form").length', 0)
        // …and the existing key is not inside the create form: its Revoke control has to
        // sit outside every form on the page, or pressing Enter in the name field reaches it.
        ->assertScript(
            'Array.from(document.querySelectorAll("form")).some(f => f.textContent.includes("Revoke"))',
            false,
        )
        ->assertNoJavaScriptErrors();

    expect($environmentId)->toBeString();
})->group('a11y');
