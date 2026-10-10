<?php

declare(strict_types=1);

use App\Platform\LastSignInMethod;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\BreachedPasswordCheck;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\NeverBreachedCheck;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Facades\Cookie;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    app()->instance(BreachedPasswordCheck::class, new NeverBreachedCheck);
    installedDeployment();
});

/*
| "LAST USED" ON THE SIGN-IN PAGE — which way this DEVICE signed in last time, kept in a
| first-party cookie holding the method alone, and drawn from a prop the server reads.
*/

it('names the method a session used, and nothing for a sign-in nobody chose', function (array $amr, ?string $method): void {
    expect(LastSignInMethod::fromAmr($amr))->toBe($method);
})->with([
    'a password' => [['pwd'], 'password'],
    'a password and a code' => [['pwd', 'otp', 'mfa'], 'password'],
    'a passkey' => [['passkey', 'pop', 'mfa'], 'passkey'],
    'Google' => [['social', 'google'], 'google'],
    'a magic link' => [['magic_link'], 'magic_link'],
    'single sign-on' => [['sso'], 'sso'],
    'impersonation' => [['impersonation'], null],
    'an invitation' => [['invitation'], null],
    'a malformed provider' => [['social', 'Not A Key!'], null],
]);

it('remembers a password sign-in in a host-only, HttpOnly cookie, and the page marks it', function (): void {
    $subject = app(Subjects::class)->create('dana@acme.test', 'Dana Reeves', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-last-used'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    $response = attemptLogin()->assertRedirect();

    $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === LastSignInMethod::COOKIE);

    expect($cookie)->not->toBeNull()
        // Host-only: each environment's host keeps its own, and no parent domain hears it.
        ->and($cookie->getDomain())->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax');

    // What it holds is the method and nothing else — no address, no account.
    $response->assertCookie(LastSignInMethod::COOKIE, 'password');
    expect((string) $cookie->getValue())->not->toContain('dana');
});

it('hands the page the last-used method, and ignores a value that is not one', function (string $stored, ?string $prop): void {
    $this->withCookie(LastSignInMethod::COOKIE, $stored)
        ->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('lastUsed', $prop));
})->with([
    'a provider' => ['google', 'google'],
    'a passkey' => ['passkey', 'passkey'],
    'markup' => ['<script>', null],
    'too long' => [str_repeat('a', 60), null],
]);

it('says nothing on a device that has never signed in', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('lastUsed', null));
});

it('leaves the cookie alone when an invitation or impersonation starts the session', function (): void {
    $subject = app(Subjects::class)->create('ivy@acme.test', 'Ivy', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-invite-last'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);

    app(PlatformAuth::class)->establish(request(), $subject->id, ['invitation']);

    expect(Cookie::hasQueued(LastSignInMethod::COOKIE))->toBeFalse();
});
