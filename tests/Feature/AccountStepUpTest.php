<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use App\Platform\Sudo;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Cbox\Id\Platform\Models\OrganizationApiKey;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Support\SessionKey;

uses(RefreshDatabase::class);

/**
 * The step-up in front of the Identity platform's machine credentials.
 *
 * This file used to be about a SECOND step-up. The account console had its own —
 * `WorkspaceSudo`, its own session key, its own `/workspace/sudo` screen — on the stated
 * ground that "a confirmation on one plane must never satisfy a step-up on the other".
 * There is one plane, so there is one step-up, and the pages that used to raise the
 * account one raise the console's. What that isolation was really protecting has not
 * moved: the ENVIRONMENT step-up is still separate, because it confirms authority over a
 * tenant rather than over yourself.
 *
 * The account-plane doors those tests also covered — the workspace sudo screen, the
 * workspace passkey ceremony, the member's own security page — are gone with the console
 * that carried them. `/sudo`, `/account` and the subject passkey ceremony are the ones
 * that remain, and they have their own coverage in SudoTest and PasskeyCeremonyTest.
 */
/** The environment the provisioned account owns — the one its owner can reach. */
function provisionedEnvironmentId(): string
{
    return (string) app(PlatformRoot::class)->run(
        fn (): mixed => Environment::query()->orderBy('created_at')->value('id'),
    );
}

function signInMember(): string
{
    // The platform root FIRST. An account provisioned without one is in the
    // first-install bootstrap window: its members have no subject, and a member
    // with no subject has nothing to sign in.
    platformRootEnvironment();

    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));
    signInAsMember($result->owner->id);

    return $result->owner->id;
}

it('redirects account API key minting to sudo when not recently confirmed', function (): void {
    signInMember();

    test()->from(route('api-keys'))
        ->post(route('api-keys.store'), ['name' => 'ci', 'role' => 'developer'])
        ->assertRedirect(route('sudo'));

    expect(session()->get('sudo.intended'))->toBe(route('api-keys'));
});

it('mints an account API key once the step-up is confirmed', function (): void {
    signInMember();
    app(Sudo::class)->confirm();

    test()->from(route('api-keys'))
        ->post(route('api-keys.store'), ['name' => 'ci', 'role' => 'developer'])
        ->assertRedirect(route('api-keys'))
        ->assertSessionHasNoErrors()
        // ON THE FLASH CHANNEL, not in props: props are written into the browser's
        // history entry, and a full-authority credential there is readable by pressing
        // Back long after the page that showed it has gone.
        ->assertInertiaFlash('freshKey');

    $flash = session()->get(SessionKey::FLASH_DATA, []);

    expect(is_array($flash) ? ($flash['freshKey'] ?? null) : null)
        ->toBeString()
        ->toStartWith('cbid_org_');
});

it('redirects environment key minting to sudo when not recently confirmed', function (): void {
    signInMember();

    // A REACHABLE environment, because the reachability check runs BEFORE the step-up —
    // authorization first, so a member who may not mint anything is refused outright
    // rather than handed a password prompt and then refused in silence once they have
    // typed it.
    issueEnvironmentKey(reachableEnvironmentId(), ['name' => 'ci'])
        ->assertRedirect(route('sudo'));

    expect(session()->get('sudo.intended'))->toBe(route('environment-keys'))
        ->and(EnvironmentApiKey::query()->where('name', 'ci')->exists())
        ->toBeFalse('an environment key was minted with no step-up');
});

/**
 * Revoking a machine credential is as consequential as minting one, and was ungated.
 *
 * A stolen but non-sudo session could not create persistence — issuing requires the
 * step-up — but it could destroy the keys that run provisioning and automation, which is
 * a denial of service the same session was otherwise held back from. Both key pages
 * gated create and neither gated revoke.
 */
it('requires the step-up to revoke an account key, not just to mint one', function (): void {
    signInMember();

    // Mint with sudo confirmed, then drop back to an unconfirmed session — the shape a
    // stolen cookie has.
    app(Sudo::class)->confirm();
    test()->from(route('api-keys'))
        ->post(route('api-keys.store'), ['name' => 'automation', 'role' => 'developer'])
        ->assertSessionHasNoErrors();

    $keyId = OrganizationApiKey::query()->where('name', 'automation')->value('id');

    expect($keyId)->toBeString();

    session()->forget(Sudo::SESSION_KEY);

    test()->from(route('api-keys'))
        ->delete(route('api-keys.destroy', $keyId))
        ->assertRedirect(route('sudo'));

    expect(OrganizationApiKey::query()->whereKey($keyId)->value('revoked_at'))
        ->toBeNull('a non-sudo session revoked a machine credential');
});

/**
 * A step-up attests that the person at this keyboard is who the session says. Every
 * transition below breaks that premise, and Laravel's `regenerate()` rotates the id while
 * PRESERVING the data — so a confirmation made before one carried straight through it.
 *
 * Not exploitable today, because the impersonation call guard refuses the actions a
 * carried-over sudo would unlock. That is the reason to fix it rather than the reason not
 * to: sudo is meant to be the independent second layer, and right now it is load-bearing
 * on whatever else happens to refuse first — which is precisely the single-layer
 * arrangement that let an impersonator reach the authorize endpoint.
 */
it('does not carry a step-up across a session transition', function (string $transition): void {
    signInMember();
    app(Sudo::class)->confirm();

    expect(app(Sudo::class)->confirmed())->toBeTrue();

    match ($transition) {
        'establish' => app(PlatformAuth::class)->establish(request(), 'subject-x', ['pwd']),
        'organization switch' => app(PlatformAuth::class)->switchOrganization(request(), 'org-elsewhere'),
    };

    expect(app(Sudo::class)->confirmed())
        ->toBeFalse("the step-up survived a {$transition}");
})->with(['establish', 'organization switch']);

/**
 * And the same through the account-membership door, which is where it bites hardest:
 * `establish()` is what an accepted invitation lands on — a sign-in where no password is
 * presented to THIS session at all.
 */
it('ends the step-up window when a member session is established', function (): void {
    $subjectId = signInMember();

    app(Sudo::class)->confirm();
    expect(app(Sudo::class)->confirmed())->toBeTrue();

    app(PlatformRoot::class)->run(fn () => app(PlatformAuth::class)->establish(request(), $subjectId, ['pwd']));

    expect(app(Sudo::class)->confirmed())
        ->toBeFalse('a passwordless account sign-in inherited an open elevation');
});
