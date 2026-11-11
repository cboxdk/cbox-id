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
    intending('https://evil.test/orgs/acme/projects/acme/environments/acme/organizations');

    // Refused outright rather than reasoned about: the path below would otherwise be
    // satisfied by the console's address on somebody else's origin.
    expect(IntendedUrl::pullForSubject())->toBeNull();
})->group('security');

it('refuses a backslash path a browser would read as protocol-relative', function (): void {
    // `parse_url()` reports NO host and a path for this, so it passes the off-host check
    // above — and a browser normalises the backslashes into slashes and follows the
    // result to evil.test as a protocol-relative URL.
    //
    // ASSERTED ON THE SUBJECT PLANE, and that is the whole point. `/\evil.test/x` does not
    // begin with `/admin`, so the admin console's own path claim rejects it before the
    // backslash check is ever consulted — my first version of this test asserted through
    // the admin console's own claim and passed with the backslash check deleted. The subject
    // plane's claim is the negation, so it ACCEPTS this path, and the backslash check is
    // then the only thing standing between the key and an off-site redirect.
    intending('/\\evil.test/x');

    expect(IntendedUrl::pullForSubject())->toBeNull();
})->group('security');

it('still returns an ordinary same-host console path', function (): void {
    intending('/orgs/acme/projects/acme/environments/acme/organizations');

    // The positive control. A guard that refused everything would satisfy both tests above
    // while breaking the redirect-after-sign-in this key exists to provide: an environment
    // console on the app host sends a signed-out visitor to sign in, and back.
    expect(IntendedUrl::pullForSubject())->toBe('/orgs/acme/projects/acme/environments/acme/organizations');
})->group('security');

/**
 * The plane split on a tenant's own host: the environment console's OLD address there is
 * not an end-user page, so an intent naming it is dropped rather than followed into the
 * redirect that now sends it to the app host.
 */
it('does not hand an old environment-console address to a tenant\'s end-user sign-in', function (): void {
    intending('/admin/organizations');
    expect(IntendedUrl::pullForSubject())->toBeNull();

    intending('/device?user_code=GMGF-WDZW');
    expect(IntendedUrl::pullForSubject())->toBe('/device?user_code=GMGF-WDZW');
})->group('security');

it('consumes the key so a later sign-in does not trip on it', function (): void {
    intending('/admin/organizations');

    expect(IntendedUrl::pullForSubject())->toBeNull()
        // Pulled, not read, even when refused: leaving it behind is how an administrator
        // got bounced in a loop between two planes that each kept rewriting the other's intent.
        ->and(session('url.intended'))->toBeNull();
})->group('security');
