<?php

declare(strict_types=1);

use App\Platform\BreachedPasswords;
use App\Platform\Enums\AttemptOutcome;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\BreachedPasswordCheck;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\NeverBreachedCheck;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

// Policy enforcement is the subject here, not the breach lookup — keep it offline.
beforeEach(fn () => app()->instance(BreachedPasswordCheck::class, new NeverBreachedCheck));

it('binds the real breach check so requireBreachCheck is not silently inert', function (): void {
    // The framework ships a deliberately-inert default; the app must replace it, or a
    // policy demanding a breach check would pass everything while appearing enforced.
    app()->forgetInstance(BreachedPasswordCheck::class);
    expect(app(BreachedPasswordCheck::class))->toBeInstanceOf(BreachedPasswords::class);
});

it('refuses password sign-in for a subject whose organization mandates SSO', function (): void {
    $subject = app(Subjects::class)->create('sso@acme.test', 'Dana', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-'.uniqid()));
    app(Memberships::class)->add($org->id, $subject->id, 'member');

    $auth = app(PlatformAuth::class);
    $request = Request::create('/login', 'POST');

    // With SSO off, the correct password signs in.
    expect($auth->attemptPassword($request, 'sso@acme.test', 'a-strong-unbreached-passphrase'))
        ->toBe(AttemptOutcome::Ok);

    // Once the organization mandates SSO, the SAME correct password is refused — a local
    // credential must not be a way around the identity provider the tenant chose.
    app(AuthPolicies::class)->setForOrganization($org->id, new AuthPolicy(sso: SsoEnforcement::Required));

    expect($auth->attemptPassword($request, 'sso@acme.test', 'a-strong-unbreached-passphrase'))
        ->toBe(AttemptOutcome::Invalid);
});

it('lets an environment mandate SSO for every organization at once', function (): void {
    $subject = app(Subjects::class)->create('envsso@acme.test', 'Sam', 'a-strong-unbreached-passphrase');

    $auth = app(PlatformAuth::class);
    $request = Request::create('/login', 'POST');

    expect($auth->attemptPassword($request, 'envsso@acme.test', 'a-strong-unbreached-passphrase'))
        ->toBe(AttemptOutcome::Ok);

    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(sso: SsoEnforcement::Required));

    expect($auth->attemptPassword($request, 'envsso@acme.test', 'a-strong-unbreached-passphrase'))
        ->toBe(AttemptOutcome::Invalid);
});

// The strictest membership wins: holding a second, laxer membership must not become a
// way around a mandating tenant.
it('refuses password sign-in when ANY of the subject\'s organizations mandates SSO', function (): void {
    $subject = app(Subjects::class)->create('multi@acme.test', 'Rae', 'a-strong-unbreached-passphrase');
    $orgs = app(Organizations::class);
    $lax = $orgs->create(new NewOrganization('Lax Corp', 'lax-'.uniqid()));
    $strict = $orgs->create(new NewOrganization('Strict Corp', 'strict-'.uniqid()));

    $memberships = app(Memberships::class);
    $memberships->add($lax->id, $subject->id, 'member');
    $memberships->add($strict->id, $subject->id, 'member');

    app(AuthPolicies::class)->setForOrganization($strict->id, new AuthPolicy(sso: SsoEnforcement::Required));

    expect(app(PlatformAuth::class)->attemptPassword(
        Request::create('/login', 'POST'),
        'multi@acme.test',
        'a-strong-unbreached-passphrase',
    ))->toBe(AttemptOutcome::Invalid);
});
