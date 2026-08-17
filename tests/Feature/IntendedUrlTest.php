<?php

declare(strict_types=1);

use App\Platform\IntendedUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @group security
 *
 * The intended-URL key is a redirect target, so what it refuses is an open redirect.
 *
 * `IntendedUrl` states two refusals and explains the second at length — a browser reads a
 * backslash as a slash, so `/\evil.test/x` parses as host-less with a path here and is
 * normalised by Chrome and Firefox into a protocol-relative URL pointing somewhere else.
 * Neither refusal had a test: deleting the off-host check, and deleting the backslash
 * check, each left every suite that touches this key green.
 *
 * The class is honest that nothing writes a user-supplied value into the key today, and
 * calls that "a fact about the callers, not a property of this class". That is exactly
 * why it is worth pinning — the fact about the callers is one refactor from changing, and
 * the property is what would still hold.
 */
function intending(string $url): void
{
    session(['url.intended' => $url]);
}

it('refuses an intended URL naming another host', function (): void {
    intending('https://evil.test/admin/organizations');

    // Refused outright rather than reasoned about: the path claim below would otherwise
    // be satisfied by `/admin/organizations` on somebody else's origin.
    expect(IntendedUrl::pullForAdminConsole())->toBeNull();
})->group('security');

it('refuses a backslash path a browser would read as protocol-relative', function (): void {
    // `parse_url()` reports NO host and a path for this, so it passes the off-host check
    // above — and a browser normalises the backslashes into slashes and follows the
    // result to evil.test as a protocol-relative URL.
    //
    // ASSERTED ON THE SUBJECT PLANE, and that is the whole point. `/\evil.test/x` does not
    // begin with `/admin`, so the admin console's own path claim rejects it before the
    // backslash check is ever consulted — my first version of this test asserted through
    // `pullForAdminConsole()` and passed with the backslash check deleted. The subject
    // plane's claim is the negation, so it ACCEPTS this path, and the backslash check is
    // then the only thing standing between the key and an off-site redirect.
    intending('/\\evil.test/x');

    expect(IntendedUrl::pullForSubject())->toBeNull();
})->group('security');

it('still returns an ordinary same-host admin path', function (): void {
    intending('/admin/organizations');

    // The positive control. A guard that refused everything would satisfy both tests above
    // while breaking the redirect-after-sign-in this key exists to provide.
    expect(IntendedUrl::pullForAdminConsole())->toBe('/admin/organizations');
})->group('security');

/**
 * The plane split, which is the other half of what this class is for: one host serves both
 * the environment admin console and the tenant's end-user pages, they share a cookie and
 * therefore this key, and neither may open the other's pages.
 */
it('does not hand an end-user page to the admin console, or the reverse', function (): void {
    intending('/device?user_code=GMGF-WDZW');
    expect(IntendedUrl::pullForAdminConsole())->toBeNull();

    intending('/admin/organizations');
    expect(IntendedUrl::pullForSubject())->toBeNull();
})->group('security');

it('consumes the key so a later sign-in on the other plane does not trip on it', function (): void {
    intending('/admin/organizations');

    expect(IntendedUrl::pullForAdminConsole())->toBe('/admin/organizations')
        // Pulled, not read: leaving it behind is how an administrator got bounced in a
        // loop between two planes that each kept rewriting the other's intent.
        ->and(session('url.intended'))->toBeNull();
})->group('security');
