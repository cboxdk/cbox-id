<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Enums\GrantPollStatus;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * Sign in a non-admin member and return [subjectId, orgId].
 *
 * @return array{0: string, 1: string}
 */
function approvalsMember(): array
{
    $subject = app(Subjects::class)->create('member@acme.test', 'Member User', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-approvals'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Member);

    // AND THE SESSION KEY THE GUARD ON THE WAY IN READS: the page is reached by a REQUEST
    // now, and without this it answers a redirect to /login — which every assertion about
    // what the page does NOT show would pass against.
    session([PlatformAuth::SESSION_KEY => $session->id]);

    return [$subject->id, $org->id];
}

it('lets a user approve a pending agent request', function (): void {
    [$subjectId] = approvalsMember();

    $client = app(ClientRegistry::class)->register(
        new NewClient('Agent', ClientType::Confidential, scopes: ['openid'])
    )->client;

    app(BackchannelAuthentication::class)->request($client, ['openid'], $subjectId);

    $requestId = BackchannelAuthRequest::query()->where('user_id', $subjectId)->value('id');

    test()->get(route('approvals'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('requests', fn (Collection $rows): bool => $rows->pluck('app')->all() === ['Agent']));

    test()->from(route('approvals'))
        ->post(route('approvals.approve', $requestId))
        ->assertSessionHasNoErrors();

    expect(BackchannelAuthRequest::query()->whereKey($requestId)->value('status'))->toBe(GrantPollStatus::Approved);
});

/**
 * WHAT THE PERSON SEES MUST COVER WHAT THE AGENT GETS.
 *
 * The token minted on approval carries the scopes fixed at REQUEST time —
 * `CibaAuthenticationService::approve()` writes only status, org and timestamp, and never
 * touches them. So the page is the whole of the consent: whatever it fails to show is
 * authority granted unseen.
 *
 * The page renders `$labels[$scope] ?? $scope`, so a scope nobody has written a friendly
 * label for still appears as its raw string. That fallback is the safety property, and it
 * is one refactor away from being an `array_filter` that quietly drops the unknown ones —
 * which would read as a tidier list and be a consent screen that lies.
 */
it('shows every requested scope, including one it has no label for', function (): void {
    [$subjectId] = approvalsMember();

    $client = app(ClientRegistry::class)->register(
        new NewClient('Agent', ClientType::Confidential, scopes: ['openid', 'deploy:production'])
    )->client;

    app(BackchannelAuthentication::class)->request($client, ['openid', 'deploy:production'], $subjectId);

    test()->get(route('approvals'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('requests', fn (Collection $rows): bool => collect($rows->first()['scopes'])->all() === [
                // The labelled one, carried as its human phrase…
                ['value' => 'openid', 'label' => 'Verify your identity'],
                // …and the unlabelled one, carried as itself rather than omitted. Asserted
                // as the WHOLE list rather than two containment checks, because the failure
                // this guards against is a scope silently dropped.
                ['value' => 'deploy:production', 'label' => 'deploy:production'],
            ]));
})->group('security');

it('lets a user deny a pending agent request', function (): void {
    [$subjectId] = approvalsMember();

    $client = app(ClientRegistry::class)->register(
        new NewClient('Agent', ClientType::Confidential, scopes: ['openid'])
    )->client;

    app(BackchannelAuthentication::class)->request($client, ['openid'], $subjectId);

    $requestId = BackchannelAuthRequest::query()->where('user_id', $subjectId)->value('id');

    test()->from(route('approvals'))
        ->post(route('approvals.deny', $requestId))
        ->assertSessionHasNoErrors();

    expect(BackchannelAuthRequest::query()->whereKey($requestId)->value('status'))->toBe(GrantPollStatus::Denied);
});

it('only shows the current user their own requests', function (): void {
    [$subjectId] = approvalsMember();

    // A request that belongs to somebody else must never appear on this user's page.
    $other = app(Subjects::class)->create('other@acme.test', 'Other User', 'supersecret123');
    $client = app(ClientRegistry::class)->register(
        new NewClient('OtherAgent', ClientType::Confidential, scopes: ['openid'])
    )->client;
    app(BackchannelAuthentication::class)->request($client, ['openid'], $other->id);

    test()->get(route('approvals'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('requests', []));

    expect(BackchannelAuthRequest::query()->where('user_id', $subjectId)->count())->toBe(0);

    // AND THE WRITE, which the read above says nothing about: the page not listing somebody
    // else's request is not the same claim as this person being unable to answer it.
    $theirs = BackchannelAuthRequest::query()->where('user_id', $other->id)->value('id');

    test()->from(route('approvals'))->post(route('approvals.approve', $theirs));

    expect(BackchannelAuthRequest::query()->whereKey($theirs)->value('status'))
        ->toBe(GrantPollStatus::Pending);
});
