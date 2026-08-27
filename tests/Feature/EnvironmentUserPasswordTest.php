<?php

declare(strict_types=1);

use App\Mail\AdminAssignedPasswordMail;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

// The console validates against the breached-password list; keep the suite offline.
beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/** Provision an environment, pin an env-admin session, and return a target subject id. */
function pwUserSetup(): string
{
    // The `/admin` prefix only exists on the multi-tenant shape.
    multiTenantDeployment();

    platformRootEnvironment();
    $r = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($r->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($r->environment->id));
    actAsEnvironmentAdmin($r->owner->id, $r->environment->id);

    // The takeover actions on this page demand a fresh credential, the same as the vault
    // and legacy-login two screens away — see EnvironmentUserTakeoverTest for why.
    confirmEnvironmentStepUp();

    return app(Subjects::class)->create('dana@acme.example', 'Dana', 'the-original-passphrase')->id;
}

it('sets a temporary password and reveals it once, off the history entry', function (): void {
    $userId = pwUserSetup();

    setUserPassword($userId, [
        'password' => 'a-handed-over-temporary-passphrase',
        'reason' => 'Locked out after losing their phone',
    ])->assertSessionHasNoErrors();

    /*
     * ON THE FLASH CHANNEL, which is what puts it OUT of the browser's history entry: a
     * page prop is written into history state, so a credential there is readable by
     * pressing Back long after the screen that showed it has gone.
     */
    test()->get(route('environment.users.show', $userId))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->hasFlash('issuedPassword', 'a-handed-over-temporary-passphrase'));

    // ...and the credential really changed, with a standing change requirement.
    $subjects = app(Subjects::class);
    expect($subjects->verifyPassword($userId, 'a-handed-over-temporary-passphrase'))->toBeTrue()
        ->and($subjects->verifyPassword($userId, 'the-original-passphrase'))->toBeFalse()
        ->and(app(AdminPasswords::class)->requiresChange($userId))->toBeTrue();
});

it('shows a revealed password exactly once', function (): void {
    // ONCE. The flash is spent by the render that displays it, so a reload of the same
    // screen — or a Back into it — has nothing to show. This is the half of "shown once"
    // that the reveal test above cannot state.
    $userId = pwUserSetup();

    setUserPassword($userId, ['password' => 'a-single-showing-passphrase']);

    test()->get(route('environment.users.show', $userId))
        ->assertInertia(fn (AssertableInertia $page) => $page->hasFlash('issuedPassword'));

    test()->get(route('environment.users.show', $userId))
        ->assertInertia(fn (AssertableInertia $page) => $page->missingFlash('issuedPassword'));
});

it('emails the password instead when the admin chooses that delivery', function (): void {
    $userId = pwUserSetup();
    Mail::fake();

    setUserPassword($userId, [
        'password' => 'an-emailed-temporary-passphrase',
        'reason' => 'Requested by their manager',
        'delivery' => 'email',
    ])->assertSessionHasNoErrors();

    Mail::assertSent(AdminAssignedPasswordMail::class);

    // The credential must NOT also be revealed on screen when it was emailed.
    test()->get(route('environment.users.show', $userId))
        ->assertInertia(fn (AssertableInertia $page) => $page->missingFlash('issuedPassword'));
});

it('honours the chosen blast radius for existing sessions', function (): void {
    $userId = pwUserSetup();
    $sessions = app(SessionManager::class);

    $keep = $sessions->start($userId, null, ['pwd'])->id;
    setUserPassword($userId, [
        'password' => 'a-quiet-replacement-passphrase',
        'reason' => 'Routine rotation',
        'revoke' => 'nothing',
    ])->assertSessionHasNoErrors();
    expect($sessions->active($keep))->not->toBeNull();

    $cut = $sessions->start($userId, null, ['pwd'])->id;
    setUserPassword($userId, [
        'password' => 'a-disruptive-replacement-passphrase',
        'reason' => 'Suspected compromise',
        'revoke' => 'sessions_and_tokens',
    ])->assertSessionHasNoErrors();
    expect($sessions->active($cut))->toBeNull();
});

it('requires a reason and a strong password', function (): void {
    $userId = pwUserSetup();

    setUserPassword($userId, ['password' => 'short', 'reason' => ''])
        ->assertSessionHasErrors(['password', 'reason']);

    // Nothing was written on a rejected attempt.
    expect(app(Subjects::class)->verifyPassword($userId, 'the-original-passphrase'))->toBeTrue();
});

// The capability gate: reaching an environment is not the same as administering it.
it('refuses a member without the environment-admin capability', function (): void {
    multiTenantDeployment();
    platformRootEnvironment();
    $r = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner2@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    $environment = serveOnTestHost($r->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($r->environment->id));

    [$viewer, $viewerSubjectId] = addMember($r->organization->id, MembershipRole::Viewer, 'viewer@acme.example');

    actAsEnvironmentAdmin($viewer->user_id, $r->environment->id);

    $userId = app(Subjects::class)->create('target@acme.example', 'Target', 'the-original-passphrase')->id;

    /*
     * REFUSED AT THE DOOR AND AT THE WRITE ALIKE. The capability middleware sits on the
     * whole prefix, so the write is refused before the controller is reached at all — and
     * this holds BOTH, because "the page 403s" is not a statement about the endpoint the
     * form posts to.
     *
     * The refusal is a bounce back to the account host to re-open an environment this
     * member cannot administer, so it is FOLLOWED TO THE END rather than asserted as "a
     * 302": a redirect to the step-up screen would also be a 302, and would mean this
     * viewer is being invited to confirm their way into a capability they were never
     * granted. The chain has to terminate in a refusal.
     */
    $handoff = 'https://'.config('cbox-id.tenancy.account_host').'/open/'.$environment->id;

    test()->get(route('environment.users.show', $userId))->assertRedirect($handoff);
    setUserPassword($userId)->assertRedirect($handoff);

    expect(app(Subjects::class)->verifyPassword($userId, 'the-original-passphrase'))->toBeTrue();

    // Followed LAST: asking for the handoff moves this client — and the environment
    // context with it — onto the account host, so everything scoped to the tenant
    // environment has to have been asked already.
    test()->get($handoff)->assertForbidden();
});
