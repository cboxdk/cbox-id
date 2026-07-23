<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

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
    app(Memberships::class)->add($org->id, $subject->id, 'member');
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Member);

    return [$subject->id, $org->id];
}

it('lets a user approve a pending agent request', function (): void {
    [$subjectId] = approvalsMember();

    $client = app(ClientRegistry::class)->register(
        new NewClient('Agent', ClientType::Confidential, scopes: ['openid'])
    )->client;

    app(BackchannelAuthentication::class)->request($client, ['openid'], $subjectId);

    $requestId = BackchannelAuthRequest::query()->where('user_id', $subjectId)->value('id');

    Volt::test('approvals')
        ->assertSee('Agent')
        ->call('approve', $requestId)
        ->assertHasNoErrors();

    expect(BackchannelAuthRequest::query()->whereKey($requestId)->value('status'))->toBe('approved');
});

it('lets a user deny a pending agent request', function (): void {
    [$subjectId] = approvalsMember();

    $client = app(ClientRegistry::class)->register(
        new NewClient('Agent', ClientType::Confidential, scopes: ['openid'])
    )->client;

    app(BackchannelAuthentication::class)->request($client, ['openid'], $subjectId);

    $requestId = BackchannelAuthRequest::query()->where('user_id', $subjectId)->value('id');

    Volt::test('approvals')
        ->call('deny', $requestId)
        ->assertHasNoErrors();

    expect(BackchannelAuthRequest::query()->whereKey($requestId)->value('status'))->toBe('denied');
});

it('only shows the current user their own requests', function (): void {
    [$subjectId] = approvalsMember();

    // A request that belongs to somebody else must never appear on this user's page.
    $other = app(Subjects::class)->create('other@acme.test', 'Other User', 'supersecret123');
    $client = app(ClientRegistry::class)->register(
        new NewClient('OtherAgent', ClientType::Confidential, scopes: ['openid'])
    )->client;
    app(BackchannelAuthentication::class)->request($client, ['openid'], $other->id);

    Volt::test('approvals')->assertDontSee('OtherAgent');

    expect(BackchannelAuthRequest::query()->where('user_id', $subjectId)->count())->toBe(0);
});
