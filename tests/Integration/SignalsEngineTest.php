<?php

declare(strict_types=1);

use App\Platform\Enums\AttemptOutcome;
use App\Platform\PlatformAuth;
use App\Platform\Radar\RadarDevices;
use App\Platform\Signals\SignalsAuthenticationAssessment;
use Cbox\Id\Identity\Contracts\AuthenticationAssessment;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Signals\Data\Entity;
use Cbox\Signals\Data\EvaluationRequest;
use Cbox\Signals\Data\EvaluationStatus;
use Cbox\Signals\Data\Event;
use Cbox\Signals\Data\EventBatch;
use Cbox\Signals\Data\OperationState;
use Cbox\Signals\HttpSignals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('uses actual engine evidence before password issuance and finishes actual TOTP without looping', function (): void {
    $browser = getenv('SIGNALS_AUTH_BROWSER_PROOF') === '1';
    if ($browser) {
        installedDeployment();
        Environment::query()->create([
            'id' => '01J00000000000000000000000', 'name' => 'Signals acceptance',
            'slug' => 'signals-acceptance', 'type' => EnvironmentType::Production,
            'status' => EnvironmentStatus::Active,
            'is_default' => true, 'settings' => [],
        ]);
        platformRootDeployment();
    }
    $environment = app(EnvironmentContext::class)->requireEnvironment()->environmentKey();
    $path = getenv('SIGNALS_AUTH_PROOF_CONFIG');
    if (! is_string($path) || ! is_file($path)) {
        throw new RuntimeException('Run the isolated signals-auth-roundtrip.py harness. This acceptance never substitutes a fake engine.');
    }
    $config = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (! str_starts_with($config['url'], 'http://127.0.0.1:')) {
        throw new RuntimeException('This acceptance requires the isolated loopback engine.');
    }
    $client = new HttpSignals(app(Factory::class), $config['url'], $config['token'], 2);
    $user = app(Subjects::class)->create('engine-'.Str::uuid().'@example.test', 'Signals acceptance', 'a-strong-unbreached-passphrase');

    app(Subjects::class)->markEmailVerified($user->id, $user->email);
    $org = app(Organizations::class)->create(new NewOrganization('Signals acceptance', 'signals-'.Str::uuid()));
    app(Memberships::class)->add($org->id, $user->id, MembershipRole::Owner);

    $at = new DateTimeImmutable('-10 seconds');
    $events = [];
    for ($i = 0; $i < 2; $i++) {
        $events[] = new Event((string) Str::uuid(), 'customer.login_completed', 1, $at, ['user' => $user->id, 'device' => 'other-verified-device', 'result' => 'succeeded']);
    }
    for ($i = 0; $i < 3; $i++) {
        $events[] = new Event((string) Str::uuid(), 'customer.authorization_denied', 1, $at, ['user' => $user->id, 'resource' => 'resource-'.$i, 'result' => 'denied']);
    }
    $receipt = $client->events(new EventBatch('customer', $events), (string) Str::uuid());
    $deadline = microtime(true) + 20;
    do {
        $operation = $client->operation($receipt->operationId);
        if ($operation->status === OperationState::Completed) {
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    expect($operation->status)->toBe(OperationState::Completed);
    do {
        $evaluation = $client->evaluate(new EvaluationRequest((string) Str::uuid(), new Entity('cbox-id', 'user', $user->id), 'new-device'));
        if ($evaluation->status === EvaluationStatus::Evaluated && count(array_filter($evaluation->signals, fn ($s) => $s->severity === 'high')) > 0) {
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    expect($evaluation->status)->toBe(EvaluationStatus::Evaluated)
        ->and(count(array_filter($evaluation->signals, fn ($s) => $s->severity === 'high')))->toBeGreaterThan(0);

    $request = Request::create('/login', 'POST');
    $request->setLaravelSession(session()->driver());
    app()->bind(AuthenticationAssessment::class, fn () => new SignalsAuthenticationAssessment(
        new HttpSignals(app(Factory::class), $config['url'], $config['token'], 0.080),
        app(EnvironmentContext::class), app(RadarDevices::class), $browser ? app(Request::class) : $request, app(AuditLog::class), $environment,
    ));
    $auth = app(PlatformAuth::class);
    expect($auth->attemptPassword($request, $user->email, 'a-strong-unbreached-passphrase'))->toBe(AttemptOutcome::Passkey)
        ->and(Session::query()->where('user_id', $user->id)->count())->toBe(0);

    if ($browser) {
        $page = visit('/login');
        $page->assertSee('Sign in')->fill('email', $user->email)->press('Continue');
        $page->assertSee('Password')->fill('input[type="password"]', 'a-strong-unbreached-passphrase')->click('button[type="submit"]');
        $page->assertSee('Sign in with a passkey')->assertPathIs('/login')->assertNoJavaScriptErrors();
        expect(Session::query()->where('user_id', $user->id)->count())->toBe(0);
    }

    // Enrolment is a fixture operation through ID's own public package contract;
    // the actual factor verification below is the production authentication path.
    $factor = app(Mfa::class)->enrollTotp($user->id, $user->email);
    confirmTotpForTest($user->id, $factor->secret);
    if ($browser) {
        $page->navigate('/login')->fill('email', $user->email)->press('Continue');
        $page->assertSee('Password')->fill('input[type="password"]', 'a-strong-unbreached-passphrase')->click('button[type="submit"]');
        $page->assertSee('Two-factor verification')->assertPathIs('/mfa');
        $page->fill('code', app(TotpAuthenticator::class)->codeAt($factor->secret, time()))->press('Verify');
        $page->assertSee('Welcome back')->assertPathIs('/dashboard')->assertNoJavaScriptErrors();
        expect(Session::query()->where('user_id', $user->id)->count())->toBe(1);

    } else {
        expect($auth->attemptPassword($request, $user->email, 'a-strong-unbreached-passphrase'))->toBe(AttemptOutcome::Mfa);
        $code = app(TotpAuthenticator::class)->codeAt($factor->secret, time());
        expect($auth->completeMfa($request, $code))->toBeTrue()
            ->and(Session::query()->where('user_id', $user->id)->count())->toBe(1);
    }

    // Read the service's public feed after issuance; assessment itself must not
    // invent a successful login. Provision only the test read key via ID's contract.
    $feedKey = app(EnvironmentApiKeys::class)->issue($environment, 'Signals acceptance feed', ['events:read']);
    $feed = $this->withToken($feedKey->plaintext)->getJson('/api/v1/events?'.http_build_query(['types' => ['user.session_started'], 'organization_id' => $org->id]))->assertOk();
    $items = $feed->json('data');
    expect($items)->toHaveCount(1)
        ->and($items[0]['payload']['user_id'])->toBe($user->id)
        ->and($items[0]['payload']['outcome'])->toBe('success')
        ->and($items[0]['payload']['device_id'])->toBeString()->not->toBeEmpty()
        ->and($items[0]['payload']['amr'])->toContain('mfa');
    $event = $items[0];
    $receipt = $client->events(new EventBatch('customer', [new Event($event['id'], $event['type'], 1, new DateTimeImmutable($event['occurred_at']), $event['payload'])]), (string) Str::uuid());
    $deadline = microtime(true) + 20;
    do {
        $operation = $client->operation($receipt->operationId);
        if ($operation->status === OperationState::Completed) {
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    expect($operation->status)->toBe(OperationState::Completed);
    do {
        $evaluation = $client->evaluate(new EvaluationRequest((string) Str::uuid(), new Entity('cbox-id', 'user', $user->id), $event['payload']['device_id']));
        if (($evaluation->coverage['identity'] ?? '') === 'ready') {
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    expect($evaluation->coverage['identity'])->toBe('ready');

    // A real slow HTTP service exceeds the unchanged 80 ms deadline. This must
    // continue ordinary ID policy, never synthesize an evaluated low-risk result.
    $fallbackUser = app(Subjects::class)->create('timeout-'.Str::uuid().'@example.test', 'Timeout acceptance', 'a-strong-unbreached-passphrase');
    app()->bind(AuthenticationAssessment::class, fn () => new SignalsAuthenticationAssessment(
        new HttpSignals(app(Factory::class), $config['timeout_url'], $config['token'], 0.080),
        app(EnvironmentContext::class), app(RadarDevices::class), $request, app(AuditLog::class), $environment,
    ));
    app(PlatformAuth::class)->establish($request, $fallbackUser->id, ['pwd']);
    expect(Session::query()->where('user_id', $fallbackUser->id)->count())->toBe(1);
    expect(AuditEntry::query()->where('action', 'signals.assessment_unavailable')->where('actor_id', $fallbackUser->id)->exists())->toBeTrue();

});
