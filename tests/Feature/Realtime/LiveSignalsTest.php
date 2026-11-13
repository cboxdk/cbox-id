<?php

declare(strict_types=1);

use App\Platform\Realtime\Channels;
use App\Platform\Realtime\LiveSignal;
use App\Platform\Realtime\LiveUpdates;
use App\Platform\Realtime\PublishLiveSignal;
use Cbox\Id\Devices\Enums\DevicePlatform;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * WHAT A CHANGE BECOMES ON THE SOCKET.
 *
 * Records the pages show are observed where laravel-id and the app already write them; a
 * committed change becomes one signal per channel — its environment, its organizations,
 * the people it is about — naming the topic, the kind of record, its id and the action.
 * Never a value. And only to channels somebody is listening on.
 */

beforeEach(function (): void {
    enableLiveUpdates();
    Queue::fake();
});

/** @return list<LiveSignal> */
function liveSignals(): array
{
    app(LiveUpdates::class)->flush();

    return Queue::pushed(PublishLiveSignal::class)->map(fn (PublishLiveSignal $job): LiveSignal => $job->signal)->values()->all();
}

/** @return array<string, LiveSignal> */
function liveSignalsByChannel(): array
{
    $byChannel = [];

    foreach (liveSignals() as $signal) {
        $byChannel[$signal->channel] = $signal;
    }

    return $byChannel;
}

function watchLive(string ...$channels): void
{
    foreach ($channels as $channel) {
        app(LiveUpdates::class)->watch($channel);
    }
}

function enrolLiveDevice(string $subjectId, string $name = 'Jane’s iPhone'): Device
{
    return app(PlatformRoot::class)->run(fn (): Device => Device::query()->create([
        'subject_id' => $subjectId,
        'install_id' => (string) Str::ulid(),
        'platform' => DevicePlatform::Ios,
        'name' => $name,
        'status' => DeviceStatus::Active,
        'token_encrypted' => 'sealed-push-token',
    ]));
}

it('tells the person and their environment that a trusted device was enrolled — and nothing about it', function (): void {
    $account = provisionAccount('jane@acme.example');
    $root = app(PlatformRoot::class)->environment()->environmentKey();
    watchLive(Channels::subject($account['subjectId']), Channels::environment($root));
    app(LiveUpdates::class)->discard();

    $device = enrolLiveDevice($account['subjectId']);
    $signals = liveSignalsByChannel();

    expect(array_keys($signals))->toEqualCanonicalizing([Channels::subject($account['subjectId']), Channels::environment($root)]);

    $payload = $signals[Channels::subject($account['subjectId'])]->broadcastWith();
    expect($payload['topics'])->toBe(['devices'])
        ->and($payload['changes'])->toBe([['topic' => 'devices', 'type' => 'device', 'id' => $device->id, 'action' => 'created']]);

    // An invalidation signal, never data: no name, address, push token or key.
    $wire = json_encode($payload, JSON_THROW_ON_ERROR);
    expect($wire)->not->toContain('iPhone')
        ->not->toContain('jane@acme.example')
        ->not->toContain('sealed-push-token')
        ->not->toContain('Owner');

    expect($signals[Channels::subject($account['subjectId'])]->broadcastOn()[0]->name)
        ->toBe(Channels::private(Channels::subject($account['subjectId'])));
});

it('sends nothing to a channel nobody is listening on', function (): void {
    $account = provisionAccount();
    app(LiveUpdates::class)->discard();

    enrolLiveDevice($account['subjectId']);

    expect(liveSignals())->toBe([]);
});

it('is inert with broadcasting off: nothing buffered, nothing queued', function (): void {
    config(['broadcasting.default' => 'null']);
    $account = provisionAccount();
    watchLive(Channels::subject($account['subjectId']));

    enrolLiveDevice($account['subjectId']);

    expect(app(LiveUpdates::class)->pending())->toBe([])
        ->and(liveSignals())->toBe([]);
});

it('tells nobody about a change that rolled back', function (): void {
    $account = provisionAccount();
    watchLive(Channels::subject($account['subjectId']));
    app(LiveUpdates::class)->discard();

    try {
        DB::transaction(function () use ($account): void {
            enrolLiveDevice($account['subjectId']);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(liveSignals())->toBe([]);
});

it('keeps a session heartbeat off the socket, and reports a revocation as one', function (): void {
    $account = provisionAccount();
    $channel = Channels::subject($account['subjectId']);
    watchLive($channel);

    $session = app(PlatformRoot::class)->run(fn () => app(SessionManager::class)->start($account['subjectId'], $account['organization']->id, ['pwd']));
    app(LiveUpdates::class)->discard();

    app(PlatformRoot::class)->run(fn () => Session::query()->whereKey($session->id)->firstOrFail()->forceFill(['last_active_at' => now()->addMinute()])->save());
    expect(app(LiveUpdates::class)->pending())->toBe([]);

    app(PlatformRoot::class)->run(fn () => Session::query()->whereKey($session->id)->firstOrFail()->forceFill(['revoked_at' => now()])->save());
    $signals = liveSignalsByChannel();

    expect($signals[$channel]->broadcastWith()['changes'])
        ->toBe([['topic' => 'sessions', 'type' => 'session', 'id' => $session->id, 'action' => 'revoked']]);
});

it('tells the organization, the environment and the person about a new membership', function (): void {
    $acme = provisionAccount('owner@acme.example');
    $root = app(PlatformRoot::class)->environment()->environmentKey();
    $person = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->create('new@acme.example', 'New Person', 'a-strong-unbreached-passphrase'));
    watchLive(Channels::organization($acme['organization']->id), Channels::environment($root), Channels::subject($person->id));
    app(LiveUpdates::class)->discard();
    Queue::fake();

    app(PlatformRoot::class)->run(fn () => app(Memberships::class)->add($acme['organization']->id, $person->id, MembershipRole::Member));
    $signals = liveSignalsByChannel();

    expect(array_keys($signals))->toContain(Channels::organization($acme['organization']->id), Channels::environment($root), Channels::subject($person->id));
    expect($signals[Channels::organization($acme['organization']->id)]->broadcastWith()['topics'])->toContain('members');
});

it('covers bulk changes through the audit trail: sign out everywhere is one entry, and sessions', function (): void {
    $account = provisionAccount();
    $root = app(PlatformRoot::class)->environment()->environmentKey();
    watchLive(Channels::environment($root), Channels::subject($account['subjectId']));
    app(LiveUpdates::class)->discard();

    app(PlatformRoot::class)->run(fn () => app(AuditLog::class)->record(new AuditEvent(
        action: 'user.sessions_revoked',
        organizationId: $account['organization']->id,
        targetType: 'user',
        targetId: $account['subjectId'],
        context: ['email' => 'owner@acme.example'],
    )));

    $signals = liveSignalsByChannel();

    expect($signals[Channels::subject($account['subjectId'])]->broadcastWith()['topics'])->toEqualCanonicalizing(['audit', 'sessions'])
        ->and(json_encode($signals[Channels::environment($root)]->broadcastWith()))->not->toContain('owner@acme.example');
});

it('publishes a burst as one message per channel, then throttles to one trailing message', function (): void {
    $account = provisionAccount();
    $channel = Channels::subject($account['subjectId']);
    watchLive($channel);
    app(LiveUpdates::class)->discard();

    foreach (range(1, 30) as $n) {
        enrolLiveDevice($account['subjectId'], 'Phone '.$n);
    }

    $first = array_values(array_filter(liveSignals(), fn (LiveSignal $s): bool => $s->channel === $channel));
    expect($first)->toHaveCount(1)
        ->and($first[0]->broadcastWith()['changes'])->toHaveCount(20)
        ->and($first[0]->broadcastWith()['truncated'])->toBeTrue();

    // The next request inside the window: one delayed, trailing publish…
    Queue::fake();
    enrolLiveDevice($account['subjectId'], 'Phone 31');
    app(LiveUpdates::class)->flush();
    Queue::assertPushed(PublishLiveSignal::class, 1);
    Queue::assertPushed(PublishLiveSignal::class, fn (PublishLiveSignal $job): bool => $job->delay === 1 && $job->signal->changes === []);

    // …and the one after that is already covered by it.
    Queue::fake();
    enrolLiveDevice($account['subjectId'], 'Phone 32');
    app(LiveUpdates::class)->flush();
    Queue::assertNothingPushed();
});

it('broadcasts the signal from the worker as an event on the private channel', function (): void {
    Event::fake([LiveSignal::class]);

    (new PublishLiveSignal(new LiveSignal('id.subject.01ARZ3NDEKTSV4RRFFQ69G5FAV', ['devices'], [])))->handle();

    Event::assertDispatched(LiveSignal::class, fn (LiveSignal $signal): bool => $signal->broadcastAs() === 'changed'
        && $signal->broadcastOn()[0]->name === 'private-id.subject.01ARZ3NDEKTSV4RRFFQ69G5FAV');
});
