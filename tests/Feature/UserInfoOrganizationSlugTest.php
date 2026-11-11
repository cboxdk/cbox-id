<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;

/*
 * UserInfo carries each organization's slug next to its id, so an app builds `/orgs/{slug}`
 * without a management call. Additive: `sub`, `org` and every entry's `id` stay as they were.
 */

/** @param  list<string>  $scopes */
function userInfoAs(string $subject, array $scopes, ?string $org = null): void
{
    $token = Introspection::active($subject, 'client-a', $scopes, array_filter(['sub' => $subject, 'org' => $org]));
    app()->instance(TokenIntrospector::class, new class($token) implements TokenIntrospector
    {
        public function __construct(private readonly Introspection $token) {}

        public function introspect(string $token): Introspection
        {
            return $token === 'valid' ? $this->token : Introspection::inactive();
        }

        public function revoke(string $jti): void {}
    });
}

beforeEach(function (): void {
    $this->ada = app(Subjects::class)->create('ada@userinfo-slug.example', 'Ada')->id;
    $this->acme = app(Organizations::class)->create(new NewOrganization('Acme', 'acme'))->id;
    $this->globex = app(Organizations::class)->create(new NewOrganization('Globex', 'globex-labs'))->id;
    app(Memberships::class)->add($this->acme, $this->ada, MembershipRole::Member);
    app(Memberships::class)->add($this->globex, $this->ada, MembershipRole::Admin);
});

it('adds org_slug beside org and a slug to every organizations entry, leaving ids alone', function (): void {
    userInfoAs($this->ada, ['openid', 'organizations'], $this->acme);

    $claims = $this->withToken('valid')->getJson('/oauth/userinfo')->assertOk()->json();

    expect($claims['sub'])->toBe($this->ada)
        ->and($claims['org'])->toBe($this->acme)
        ->and($claims['org_name'])->toBe('Acme')
        ->and($claims['org_slug'])->toBe('acme')
        ->and(collect($claims['organizations'])->sortBy('name')->values()->all())->toBe([
            ['id' => $this->acme, 'name' => 'Acme', 'role' => 'member', 'slug' => 'acme'],
            ['id' => $this->globex, 'name' => 'Globex', 'role' => 'admin', 'slug' => 'globex-labs'],
        ]);
});

it('reads the slug live, so a renamed organization shows on the next read', function (): void {
    userInfoAs($this->ada, ['openid'], $this->globex);
    app(Organizations::class)->find($this->globex)?->forceFill(['slug' => 'globex'])->save();

    $this->withToken('valid')->getJson('/oauth/userinfo')->assertOk()
        ->assertJsonPath('org', $this->globex)
        ->assertJsonPath('org_slug', 'globex')
        ->assertJsonMissingPath('organizations');
});

it('adds nothing without an organization and leaves a refused token as the framework answers it', function (): void {
    userInfoAs($this->ada, ['openid']);

    $this->withToken('valid')->getJson('/oauth/userinfo')->assertOk()
        ->assertExactJson(['sub' => $this->ada]);

    $this->withToken('stale')->getJson('/oauth/userinfo')->assertUnauthorized()
        ->assertJsonPath('error', 'invalid_token')
        ->assertJsonMissingPath('org_slug');
});
