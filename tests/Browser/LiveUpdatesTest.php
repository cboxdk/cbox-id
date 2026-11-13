<?php

declare(strict_types=1);

use App\Platform\Realtime\LiveUpdates;
use Cbox\Id\Devices\Enums\DevicePlatform;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Str;

/*
 * LIVE, END TO END: a real Reverb, a real browser, a page left open.
 *
 * The owner's own words for the feature: "when I add a trusted device it must pop in at
 * once". So: a person has their Trusted devices page open; a handset is enrolled for them
 * elsewhere (here, written the way the enrolment writes it, then the unit of work ends);
 * the page shows it without anybody touching it, and without a full page load.
 */

/**
 * Start `reverb:start` on a free port for this test, with the suite's credentials, and
 * stop it when the test ends. The page and the publisher talk to it like production does.
 */
/**
 * @return array{0: int, 1: resource}
 */
function startTestReverb(): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
    fclose($socket);

    $env = [
        ...getenv(),
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
        'BROADCAST_CONNECTION' => 'null',
        'TELEMETRY_ENABLED' => 'false',
        'REVERB_APP_ID' => 'live-test',
        'REVERB_APP_KEY' => 'live-test-key',
        'REVERB_APP_SECRET' => 'live-test-secret',
        'REVERB_SCALING_ENABLED' => 'false',
        'REVERB_APP_RATE_LIMITING_ENABLED' => 'false',
    ];

    $process = proc_open(
        [PHP_BINARY, base_path('artisan'), 'reverb:start', '--host=127.0.0.1', '--port='.$port, '--no-interaction'],
        [0 => ['pipe', 'r'], 1 => ['file', storage_path('logs/reverb-browser-test.log'), 'a'], 2 => ['file', storage_path('logs/reverb-browser-test.log'), 'a']],
        $pipes,
        base_path(),
        $env,
    );

    expect($process)->toBeResource('reverb:start did not launch');

    $deadline = microtime(true) + 20;
    while (microtime(true) < $deadline) {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($probe !== false) {
            fclose($probe);
            break;
        }
        usleep(100_000);
    }

    // Never outlive the test, whatever ends it.
    register_shutdown_function(static function () use ($process): void {
        if (is_resource($process)) {
            proc_terminate($process);
        }
    });

    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'live-test-key',
        'broadcasting.connections.reverb.secret' => 'live-test-secret',
        'broadcasting.connections.reverb.app_id' => 'live-test',
        'broadcasting.connections.reverb.options.host' => '127.0.0.1',
        'broadcasting.connections.reverb.options.port' => $port,
        'broadcasting.connections.reverb.options.scheme' => 'http',
        'broadcasting.connections.reverb.options.useTLS' => false,
        'realtime.enabled' => true,
        'realtime.client.host' => '127.0.0.1',
        'realtime.client.port' => $port,
        'realtime.client.scheme' => 'http',
        'queue.default' => 'sync',
    ]);

    return [$port, $process];
}

it('shows a newly enrolled trusted device on an open page, without a reload', function (): void {
    pest()->browser()->timeout(20000);
    installedDeployment();
    platformRootEnvironment();
    config(['id-devices.enabled' => true]);
    [, $reverb] = startTestReverb();
    $this->beforeApplicationDestroyed(static function () use ($reverb): void {
        proc_terminate($reverb);
        proc_close($reverb);
    });

    $subjectId = app(PlatformRoot::class)->run(function (): string {
        [$subject] = accountWithOrg('live-devices@example.test');
        app(Subjects::class)->markEmailVerified($subject->id, $subject->email);

        return $subject->id;
    });
    signInAsMember($subjectId);

    $page = visit('/settings/devices');
    $page->assertDontSee('Kitchen iPad')->assertNoJavaScriptErrors();

    // A marker a full page load would wipe: whatever happens next must keep it.
    $page->script('window.__liveMarker = "still here"');

    // Wait until the page holds its subscription: the channel authorization marks it
    // watched, and Reverb confirms the subscription a moment later. Waiting through the
    // page, never with sleep(): the test server answers the browser only while the test
    // is waiting on it.
    $watched = 'realtime:watch:id.subject.'.$subjectId;
    for ($i = 0; $i < 60 && ! cache()->has($watched); $i++) {
        $page->wait(0.25);
    }
    expect(cache()->has($watched))->toBeTrue('the page never subscribed to its live channel');
    $page->wait(1);

    app(PlatformRoot::class)->run(fn (): Device => Device::query()->create([
        'subject_id' => $subjectId,
        'install_id' => (string) Str::ulid(),
        'platform' => DevicePlatform::Ios,
        'name' => 'Kitchen iPad',
        'status' => DeviceStatus::Active,
        'token_encrypted' => 'sealed-push-token',
    ]));
    // The end of the unit of work that enrolled it: what the request or job lifecycle does.
    app(LiveUpdates::class)->flush();

    for ($i = 0; $i < 40 && $page->script('document.body.innerText.includes("Kitchen iPad")') !== true; $i++) {
        $page->wait(0.25);
    }

    $page->assertSee('Kitchen iPad')
        ->assertScript('window.__liveMarker', 'still here')
        ->assertNoJavaScriptErrors();
});
