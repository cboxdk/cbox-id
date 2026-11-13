<?php

declare(strict_types=1);

use App\Platform\FrontendApi\LoginTicket;
use App\Platform\FrontendApi\LoginTickets;
use App\Platform\PlatformMailboxProof;
use Cbox\Id\FrontendApi\Contracts\PublishableKeys;
use Cbox\Id\FrontendApi\Enums\KeyMode;
use Cbox\Id\FrontendApi\FrontendApiServiceProvider;
use Cbox\Id\Identity\Contracts\MailboxProof;
use Cbox\Id\Identity\Contracts\Subjects;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The framework hands an unverified account to whoever proves its mailbox (laravel-id 2.8).
 * Its default cannot see this app's wrapped account store or its Frontend API login
 * tickets; the app's binding withdraws both, and only when the proof is for this account.
 */
beforeEach(function (): void {
    $this->app['config']->set('cbox-id.frontend_api.enabled', true);
    (new FrontendApiServiceProvider($this->app))->boot();

    $this->key = app(PublishableKeys::class)->issue('Site', KeyMode::Test, ['https://app.acme.test']);
});

it('binds the framework contract to the app\'s mailbox proof', function (): void {
    expect(app(MailboxProof::class))->toBeInstanceOf(PlatformMailboxProof::class);
});

it('withdraws the earlier password and login tickets when the mailbox is proven', function (): void {
    $subjects = app(Subjects::class);
    $earlier = $subjects->create('claimed@acme.test', 'Earlier', 'earlier-password-123');
    app(LoginTickets::class)->mint($this->key, $earlier->id, ['pwd']);

    $claimed = app(MailboxProof::class)->claim($earlier, 'Claimed@Acme.test');

    expect($claimed->emailVerified)->toBeTrue()
        ->and($subjects->verifyPassword($earlier->id, 'earlier-password-123'))->toBeFalse()
        ->and(LoginTicket::query()->where('subject_id', $earlier->id)->exists())->toBeFalse();
});

it('leaves the account alone when the proof is for another address', function (): void {
    $subjects = app(Subjects::class);
    $account = $subjects->create('mine@acme.test', 'Mine', 'my-own-password-123');
    app(LoginTickets::class)->mint($this->key, $account->id, ['pwd']);

    $result = app(MailboxProof::class)->claim($account, 'someone-else@acme.test');

    expect($result->emailVerified)->toBeFalse()
        ->and($subjects->verifyPassword($account->id, 'my-own-password-123'))->toBeTrue()
        ->and(LoginTicket::query()->where('subject_id', $account->id)->exists())->toBeTrue();
});

it('leaves a verified account alone', function (): void {
    $subjects = app(Subjects::class);
    $account = $subjects->create('owner@acme.test', 'Owner', 'owner-password-123');
    $subjects->markEmailVerified($account->id, 'owner@acme.test');
    app(LoginTickets::class)->mint($this->key, $account->id, ['pwd']);

    app(MailboxProof::class)->claim($subjects->find($account->id) ?? $account, 'owner@acme.test');

    expect($subjects->verifyPassword($account->id, 'owner-password-123'))->toBeTrue()
        ->and(LoginTicket::query()->where('subject_id', $account->id)->exists())->toBeTrue();
});
