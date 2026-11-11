<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\Impersonation;
use App\Platform\PlatformAuth;
use Cbox\Id\Devices\Contracts\PushTransport;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Testing\FakePushTransport;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Enums\GrantPollStatus;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Support\Facades\RateLimiter;

/** The reviewer is an ordinary subject without any organization membership. */
function signInAppReviewer(): Client
{
    [$id, $client] = cibaSubjectWithDevice();
    $subject = app(Subjects::class)->find($id);
    $session = app(SessionManager::class)->start($id, null, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, null);
    config(['id-devices.app_review' => [
        'environment_id' => 'env_test', 'subject_id' => $id, 'client_id' => $client->client_id,
    ]]);

    return $client;
}

beforeEach(function (): void {
    $this->withoutVite();
    app()->instance(PushTransport::class, new FakePushTransport);
});

it('requires an ordinary sign-in for both review actions', function (): void {
    $this->get('/authenticator/review')->assertRedirect();
    $this->post('/authenticator/review')->assertRedirect();
    expect(BackchannelAuthRequest::query()->count())->toBe(0);
});

it('lets the isolated account reach review and its normal enrolment page without organization access', function (): void {
    signInAppReviewer();

    $this->get('/authenticator/review')->assertOk()->assertSee('Try an approval on your iPhone');
    $this->get('/settings/devices')->assertOk();
});

it('denies review access unless every configured boundary matches', function (string $setting, mixed $value): void {
    signInAppReviewer();
    config([$setting => $value]);

    $this->get('/authenticator/review')->assertNotFound();
    $this->post('/authenticator/review')->assertNotFound();
    expect(BackchannelAuthRequest::query()->count())->toBe(0);
})->with([
    'module off' => ['id-devices.enabled', false],
    'missing subject' => ['id-devices.app_review.subject_id', null],
    'another subject' => ['id-devices.app_review.subject_id', 'someone-else'],
    'another environment' => ['id-devices.app_review.environment_id', 'env_other'],
    'missing client' => ['id-devices.app_review.client_id', null],
    'unknown client' => ['id-devices.app_review.client_id', 'cid_other'],
]);

it('refuses both actions while impersonating', function (): void {
    signInAppReviewer();
    session([Impersonation::SESSION_KEY => [
        'operator' => 'operator', 'subject' => app(CurrentUser::class)->id(),
        'org' => 'org', 'started_at' => now()->getTimestamp(),
    ]]);

    $this->get('/authenticator/review')->assertForbidden();
    $this->post('/authenticator/review')->assertForbidden();
});

it('requires a live phone owned by the reviewer', function (): void {
    signInAppReviewer();
    Device::query()->update(['status' => DeviceStatus::Revoked]);

    $this->post('/authenticator/review')->assertSessionHasErrors('review');
    expect(BackchannelAuthRequest::query()->count())->toBe(0);
});

it('sends the normal push only to the configured reviewer and ignores browser-supplied authority', function (): void {
    $client = signInAppReviewer();

    $this->post('/authenticator/review', [
        'subject_id' => 'another-person', 'client_id' => 'another-client',
        'scopes' => ['admin'], 'binding_message' => 'Do something else',
    ])->assertRedirect('/authenticator/review');

    $request = BackchannelAuthRequest::query()->sole();
    expect($request->user_id)->toBe(app(CurrentUser::class)->id())
        ->and($request->client_id)->toBe($client->client_id)
        ->and($request->scopes)->toBe(['openid'])
        ->and($request->binding_message)->toMatch('/^REVIEW [0-9]{4}$/')
        ->and($request->status)->toBe(GrantPollStatus::Pending)
        ->and($request->expires_at->diffInSeconds(now(), true))->toBeLessThanOrEqual(301)
        ->and(app(PushTransport::class)->count())->toBe(1);

    $this->get('/authenticator/review')->assertSee($request->binding_message)->assertSee('Pending')
        ->assertDontSee($request->auth_req_id_hash);

    $this->post('/authenticator/review')->assertRedirect();
    expect(BackchannelAuthRequest::query()->count())->toBe(1)
        ->and(app(PushTransport::class)->count())->toBe(1);
});

it('shows an answered or expired request and permits a fresh test', function (GrantPollStatus $status, bool $expired, string $label): void {
    signInAppReviewer();
    $this->post('/authenticator/review')->assertRedirect();
    $first = BackchannelAuthRequest::query()->sole();
    $first->update(['status' => $status, 'expires_at' => $expired ? now()->subSecond() : now()->addMinutes(5)]);

    $this->get('/authenticator/review')->assertSee($label);
    $this->post('/authenticator/review')->assertRedirect();
    expect(BackchannelAuthRequest::query()->count())->toBe(2);
})->with([
    'approved' => [GrantPollStatus::Approved, false, 'Approved'],
    'denied' => [GrantPollStatus::Denied, false, 'Denied'],
    'expired' => [GrantPollStatus::Pending, true, 'Expired'],
]);

it('limits repeated exercises to three per minute', function (): void {
    $client = signInAppReviewer();
    RateLimiter::clear('authenticator-review:env_test:'.app(CurrentUser::class)->id());

    for ($i = 0; $i < 3; $i++) {
        $this->post('/authenticator/review')->assertSessionHasNoErrors();
        BackchannelAuthRequest::query()->where('client_id', $client->client_id)->update(['status' => GrantPollStatus::Denied]);
    }

    $this->post('/authenticator/review')->assertSessionHasErrors('review');
    expect(BackchannelAuthRequest::query()->count())->toBe(3);
});
