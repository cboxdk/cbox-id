<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\MagicLink;
use Cbox\Id\Identity\Contracts\PasswordReset;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Accessibility regression guard: renders each key page and runs axe-core
 * (WCAG 2.1 A/AA) over the HTML in jsdom via a tiny Node bridge. A new unlabelled
 * control, missing form label, or broken ARIA fails the suite. Requires Node
 * (already needed for Vite); color-contrast is checked out of band.
 */
beforeEach(function (): void {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);

    if (! file_exists(base_path('node_modules/axe-core')) || ! file_exists(base_path('node_modules/jsdom'))) {
        $this->markTestSkipped('axe-core/jsdom not installed (run npm install).');
    }
});

/**
 * Run axe over an HTML string; returns the list of violations.
 *
 * @return array<int, array<string, mixed>>
 */
function axeViolations(string $html): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'a11y').'.html';
    file_put_contents($tmp, $html);

    $result = Process::path(base_path())->timeout(60)->run(['node', 'tests/a11y/axe-run.cjs', $tmp]);
    @unlink($tmp);

    expect($result->successful())->toBeTrue('axe bridge failed: '.$result->errorOutput());

    return json_decode($result->output(), true) ?: [];
}

it('has no WCAG 2.1 A/AA violations on the public auth pages', function (string $path): void {
    if ($path === '__reset__') {
        app(Subjects::class)->create('reset@acme.test', 'R', 'super-secret-1234');
        $path = '/reset-password/'.app(PasswordReset::class)->request('reset@acme.test');
    }

    $html = $this->get($path)->assertOk()->getContent();

    expect(axeViolations($html))->toBe([]);
})->with([
    'login' => '/login',
    'signup' => '/signup',
    'forgot-password' => '/forgot-password',
    'reset-password' => '__reset__',
]);

it('has no WCAG 2.1 A/AA violations on the console pages', function (string $path): void {
    $subject = app(Subjects::class)->create('a11y@acme.test', 'A11y Admin', 'super-secret-1234');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-a11y'));
    app(Memberships::class)->add($org->id, $subject->id, 'owner');

    // A magic-link redemption establishes the platform session for later requests.
    $this->get('/magic/'.app(MagicLink::class)->request('a11y@acme.test'));

    $html = $this->get($path)->assertOk()->getContent();

    expect(axeViolations($html))->toBe([]);
})->with([
    'dashboard' => '/dashboard',
    'members' => '/members',
    'connections' => '/connections',
    'roles' => '/roles',
    'clients' => '/clients',
    'webhooks' => '/webhooks',
    'audit' => '/audit',
    'settings' => '/settings',
]);
