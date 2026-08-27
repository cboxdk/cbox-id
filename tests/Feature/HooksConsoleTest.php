<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\ExternalActions\Contracts\ExternalActions;
use Cbox\Id\ExternalActions\Enums\ActionEndpointStatus;
use Cbox\Id\ExternalActions\Enums\HookPoint;
use Cbox\Id\ExternalActions\Models\ExternalActionEndpoint;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * The inline-hook console, driven on the ORGANIZATION plane.
 *
 * One component serves both planes now, and the routable index/new/show shape won over
 * the organization plane's single page: an endpoint has a lifecycle worth linking to,
 * and the one-time signing secret needs somewhere to land. The parity tests in
 * ConsoleParityTest hold the other door open; this file holds this one.
 */
function hooksAdmin(MembershipRole $role = MembershipRole::Owner): string
{
    $subject = app(Subjects::class)->create('hooks@acme.test', 'Hooks Admin', 'supersecret123');
    // VERIFIED, because that is what an established admin of an established organization
    // IS — the same reasoning `actingAsRole()` states and applies by default. An
    // unverified fixture quietly exercises the unverified-account rules instead of the
    // page under test, and then the fixture gets blamed rather than the rule.
    app(Subjects::class)->markEmailVerified($subject->id, (string) $subject->email);
    $subject = app(Subjects::class)->find($subject->id) ?? $subject;

    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-hooks'));
    app(Memberships::class)->add($org->id, $subject->id, $role);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, $role);

    // AND THE SESSION KEY THE CONSOLE'S GUARD READS ON THE WAY IN. `CurrentUser` is
    // resolved state for code already inside the process, which is all a Livewire
    // component ever needed; a ported page is reached by a REQUEST, and without this every
    // one of them answers a redirect to /login.
    session([PlatformAuth::SESSION_KEY => $session->id]);

    return $org->id;
}

it('registers a hook and reveals the signing secret once', function (): void {
    config(['cbox-id.external_actions.verify_url' => false]);
    $orgId = hooksAdmin();

    confirmConsoleStepUp();
    confirmConsoleStepUp();
    registerHook()->assertSessionHasNoErrors();

    $endpoint = ExternalActionEndpoint::query()->where('organization_id', $orgId)->firstOrFail();

    // The plaintext exists only in the response that reveals it: handed to the detail
    // page as a one-time flash, held there in a PROTECTED prop (never dehydrated into
    // the wire snapshot) and surfaced only through the render. So assert the one-time
    // reveal on the rendered output rather than reaching into component state.
    // Read BEFORE the page is loaded, because loading it is what spends the flash — and a
    // real secret rather than the empty string a page would happily render.
    $revealed = session()->get(SessionKey::FLASH_DATA)['newSecret'] ?? '';

    expect($revealed)->toMatch('/[0-9a-f]{64}/');

    /*
     * ON THE FLASH CHANNEL, and that IS the security property rather than a detail of
     * plumbing: page props are written into the browser's history entry, so a live
     * credential there is retrievable by pressing Back.
     *
     * The SAME secret, not merely some value: the page has to receive the credential the
     * registration actually minted, or the person copies something the endpoint will never
     * be sent.
     */
    test()->get(route('hooks.show', $endpoint->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/hooks/show')
            ->hasFlash('newSecret', $revealed));
});

it('reveals the secret once and never again', function (): void {
    config(['cbox-id.external_actions.verify_url' => false]);
    $orgId = hooksAdmin();

    confirmConsoleStepUp();
    confirmConsoleStepUp();
    registerHook()->assertSessionHasNoErrors();

    $endpoint = ExternalActionEndpoint::query()->where('organization_id', $orgId)->firstOrFail();

    // Only the organization console offered this, and the banner shows a live credential
    // in plaintext — an administrator who has copied it needs it gone now, not at the
    // next navigation.
    test()->get(route('hooks.show', $endpoint->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->hasFlash('newSecret'));

    /*
     * THE SECOND LOAD HAS NOTHING. A reveal-once credential that survives a refresh is not
     * revealed once — it is stored where the next person to open the tab can read it. The
     * flash channel is what makes this true, and this is the assertion that says so.
     *
     * DISMISSING it is the reader's own affordance now: by the time anybody presses that
     * button the secret is already off the server, so making the banner go is the whole
     * job and it belongs in the browser. Held in tests/Browser/HooksTest.php.
     */
    test()->get(route('hooks.show', $endpoint->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->missingFlash('newSecret'));
});

it('pauses, activates then removes an endpoint', function (): void {
    config(['cbox-id.external_actions.verify_url' => false]);
    $orgId = hooksAdmin();

    confirmConsoleStepUp();
    confirmConsoleStepUp();
    registerHook()->assertSessionHasNoErrors();

    $endpoint = ExternalActionEndpoint::query()->where('organization_id', $orgId)->firstOrFail();
    $from = route('hooks.show', $endpoint->id);

    // ONE ENDPOINT, TWO ANSWERS. The state it moves to is read from the record rather than
    // posted, so exercising it twice is what proves it reads the record at all — a toggle
    // that always paused would pass a single call.
    test()->from($from)->post(route('hooks.toggle', $endpoint->id))->assertSessionHasNoErrors();
    expect($endpoint->fresh()?->status)->toBe(ActionEndpointStatus::Paused);

    test()->from($from)->post(route('hooks.toggle', $endpoint->id))->assertSessionHasNoErrors();
    expect($endpoint->fresh()?->status)->toBe(ActionEndpointStatus::Active);

    test()->delete(route('hooks.destroy', $endpoint->id))->assertRedirect(route('hooks'));
    expect(ExternalActionEndpoint::query()->whereKey($endpoint->id)->exists())->toBeFalse();
});

/**
 * The detail page is NEW on this plane, and a detail page resolved by id is exactly
 * where a merge leaks: the environment plane never had to scope its lookup, because its
 * administrator sits above every organization in the environment.
 */
it('404s an endpoint belonging to another organization in the same environment', function (): void {
    config(['cbox-id.external_actions.verify_url' => false]);
    hooksAdmin();

    $other = app(Organizations::class)->create(new NewOrganization('Rival', 'rival-hooks'));
    $theirs = app(ExternalActions::class)
        ->register(HookPoint::TokenMinting, 'https://rival.example.test/token', $other->id)->endpoint;

    test()->get(route('hooks.show', $theirs->id))->assertNotFound();

    // And every mutation resolves the id inside the same gate rather than trusting it.
    test()->post(route('hooks.toggle', $theirs->id))->assertNotFound();
    test()->delete(route('hooks.destroy', $theirs->id))->assertNotFound();
})->group('security');

/**
 * An endpoint the ENVIRONMENT owns fires on this tenant's sign-ins, so it is shown — but
 * ExternalActions matches the acting organization exactly and would silently no-op every
 * button. A button that does nothing is worse than no button.
 */
it('shows an environment-owned endpoint to a tenant admin without offering to manage it', function (): void {
    config(['cbox-id.external_actions.verify_url' => false]);
    hooksAdmin();

    $environmentWide = app(ExternalActions::class)
        ->registerForEnvironment(HookPoint::TokenMinting, 'https://operator.example.test/token')->endpoint;

    test()->get(route('hooks.show', $environmentWide->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('hook.owner', null)
            // The controls are not offered, rather than offered and refused.
            ->where('mayManage', false));

    // And the button being absent is not the guard: the route is typeable. 403 rather than
    // 404 — this endpoint is legitimately visible here, it is simply not this
    // administrator's to change, and answering "no such thing" would deny a row the page
    // renders three lines above it.
    test()->post(route('hooks.toggle', $environmentWide->id))->assertForbidden();
    test()->delete(route('hooks.destroy', $environmentWide->id))->assertForbidden();

    expect($environmentWide->fresh()?->status)->toBe(ActionEndpointStatus::Active);
})->group('security');

it('forbids a non-admin member', function (): void {
    hooksAdmin(MembershipRole::Member);

    test()->get(route('hooks'))->assertForbidden();
    confirmConsoleStepUp();
    test()->get(route('hooks.create'))->assertForbidden();
})->group('security');
