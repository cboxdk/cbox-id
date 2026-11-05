<?php

declare(strict_types=1);

use App\Platform\Enums\AttemptOutcome;
use App\Platform\PlatformAuth;
use App\Platform\Radar\RadarDevices;
use App\Platform\Signals\SignalsAuthenticationAssessment;
use App\Platform\Signals\SignalsLoginEvents;
use App\Platform\Signals\SignalsStepUpRequired;
use Cbox\Id\Identity\Contracts\AuthenticationAssessment;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\AuthenticationIssuance;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\ValueObjects\VerifiedAuthentication;
use Cbox\Id\Kernel\Audit\Testing\FakeAuditLog;
use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Cbox\Id\Kernel\Events\Contracts\EventBus;
use Cbox\Id\Kernel\Events\ValueObjects\DomainEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Signals\Data\Evaluation;
use Cbox\Signals\Data\EvaluationStatus;
use Cbox\Signals\Data\Signal;
use Cbox\Signals\Exceptions\SignalsFailure;
use Cbox\Signals\Testing\FakeSignals;
use Illuminate\Http\Request;

function signalsAuthenticationAdapter(FakeSignals $signals, FakeAuditLog $audit, string $environment = 'env_test'): SignalsAuthenticationAssessment
{
    return new SignalsAuthenticationAssessment($signals, app(EnvironmentContext::class), app(RadarDevices::class), Request::create('/login'), $audit, $environment);
}

function signalsAuthenticationEvidence(string $severity = 'high', string $coverage = 'ready', EvaluationStatus $status = EvaluationStatus::Evaluated, int $expirySeconds = 600): Evaluation
{
    return new Evaluation('evaluation-1', $status, [new Signal('identity.unusual_context', $severity, ['new_device', 'recent_resource_probing'], new DateTimeImmutable(sprintf('%+d seconds', $expirySeconds)), 'signal-1', 4)], ['identity' => $coverage], new DateTimeImmutable, 1);
}

it('requires step up on relevant high risk and lets fresh MFA handle the same revision', function (): void {
    $signals = (new FakeSignals)->queueEvaluation(signalsAuthenticationEvidence())->queueEvaluation(signalsAuthenticationEvidence());
    $audit = new FakeAuditLog;
    $adapter = signalsAuthenticationAdapter($signals, $audit);
    expect(fn () => $adapter->assertAllowed(new VerifiedAuthentication('user-1', 'org-1', ['pwd'], time(), AuthenticationIssuance::Session)))
        ->toThrow(SignalsStepUpRequired::class);
    $adapter->assertAllowed(new VerifiedAuthentication('user-1', 'org-1', ['pwd', 'mfa'], time(), AuthenticationIssuance::Session));
    expect($audit->recorded[0]->context['decision'])->toBe('step_up')
        ->and($audit->recorded[1]->context['decision'])->toBe('continue_id_policy')
        ->and($audit->recorded[1]->context['signals'])->toBe([['id' => 'signal-1', 'revision' => 4]])
        ->and($audit->recorded[1]->context['assurance'])->toBe('urn:cbox-id:aal2')
        ->and($signals->evaluationCalls[0]->subject->id)->toBe('user-1')
        ->and($signals->evaluationCalls[0]->requestId)->not->toBe($signals->evaluationCalls[1]->requestId);
});

it('does not treat old or unknown strong authentication as fresh', function (?int $authTime): void {
    $signals = (new FakeSignals)->queueEvaluation(signalsAuthenticationEvidence());
    expect(fn () => signalsAuthenticationAdapter($signals, new FakeAuditLog)->assertAllowed(new VerifiedAuthentication('user-1', null, ['mfa'], $authTime, AuthenticationIssuance::Token)))
        ->toThrow(SignalsStepUpRequired::class);
})->with([null, 1, PHP_INT_MAX]);

it('falls back to existing ID policy on unavailable assessment without recording remote text', function (): void {
    $signals = (new FakeSignals)->queueEvaluation(new SignalsFailure('private remote token must not appear', 429));
    $audit = new FakeAuditLog;
    signalsAuthenticationAdapter($signals, $audit)->assertAllowed(new VerifiedAuthentication('user-1', null, ['pwd'], time(), AuthenticationIssuance::Session));
    expect($audit->recorded[0]->action)->toBe('signals.assessment_unavailable')
        ->and(json_encode($audit->recorded))->not->toContain('private remote');
});

it('does not challenge on incomplete stale expired or low severity evidence', function (string $severity, string $coverage, EvaluationStatus $status, int $expiry): void {
    $signals = (new FakeSignals)->queueEvaluation(signalsAuthenticationEvidence($severity, $coverage, $status, $expiry));
    $audit = new FakeAuditLog;
    signalsAuthenticationAdapter($signals, $audit)->assertAllowed(new VerifiedAuthentication('user-1', null, ['pwd'], time(), AuthenticationIssuance::Session));
    expect($audit->recorded[0]->context['decision'])->toBe('continue_id_policy');
})->with([
    ['medium', 'ready', EvaluationStatus::Evaluated, 600],
    ['high', 'insufficient_data', EvaluationStatus::InsufficientData, 600],
    ['high', 'ready', EvaluationStatus::Degraded, 600],
    ['high', 'ready', EvaluationStatus::Evaluated, -1],
]);

it('never exports a different ID environments authentication context', function (): void {
    $signals = new FakeSignals;
    $audit = new FakeAuditLog;
    signalsAuthenticationAdapter($signals, $audit, 'other-env')->assertAllowed(new VerifiedAuthentication('user-1', null, ['pwd'], time(), AuthenticationIssuance::Session));
    expect($signals->evaluationCalls)->toBe([])->and($audit->recorded)->toBe([]);
});

it('enriches only final successful logins on the existing durable public event feed', function (): void {
    $request = Request::create('/login');
    $bus = new SignalsLoginEvents(app(EventBus::class), app(EnvironmentContext::class), app(RadarDevices::class), fn (): Request => $request, 'env_test');
    $one = $bus->emit(new DomainEvent('user.session_started', ['user_id' => 'user-1', 'session_id' => 'session-1', 'amr' => ['pwd', 'mfa']], 'org-1'));
    $two = $bus->emit(new DomainEvent('user.session_started', ['user_id' => 'user-1', 'session_id' => 'session-2', 'amr' => ['pwd']], 'org-1'));
    expect($one->id)->not->toBe($two->id)
        ->and($one->payload['outcome'])->toBe('success')
        ->and($one->payload['device_id'])->toBe($two->payload['device_id'])
        ->and($one->payload['amr'])->toBe(['pwd', 'mfa'])
        ->and($one->environment_id)->toBe('env_test');
    $other = $bus->emit(new DomainEvent('user.session_revoked', ['user_id' => 'user-1'], 'org-1'));
    expect($other->payload)->not->toHaveKey('outcome');
});

it('does not create a session for a correct password when high risk has no enrolled factor', function (): void {
    $user = app(Subjects::class)->create('signals-no-factor@example.test', 'No factor', 'a-strong-unbreached-passphrase');
    $signals = (new FakeSignals)->queueEvaluation(signalsAuthenticationEvidence());
    app()->instance(AuthenticationAssessment::class, signalsAuthenticationAdapter($signals, new FakeAuditLog));
    $request = Request::create('/login', 'POST');
    expect(app(PlatformAuth::class)->attemptPassword($request, $user->email, 'a-strong-unbreached-passphrase'))
        ->toBe(AttemptOutcome::Passkey);
    expect(Session::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(session()->has('cbox.session'))->toBeFalse();
});

it('completes existing TOTP verification against high risk without looping', function (): void {
    $user = app(Subjects::class)->create('signals-mfa@example.test', 'MFA', 'a-strong-unbreached-passphrase');
    $factor = app(Mfa::class)->enrollTotp($user->id, $user->email);
    confirmTotpForTest($user->id, $factor->secret);
    $signals = (new FakeSignals)->queueEvaluation(signalsAuthenticationEvidence());
    app()->instance(AuthenticationAssessment::class, signalsAuthenticationAdapter($signals, new FakeAuditLog));
    $request = Request::create('/login', 'POST');
    $request->setLaravelSession(session()->driver());
    $auth = app(PlatformAuth::class);
    expect($auth->attemptPassword($request, $user->email, 'a-strong-unbreached-passphrase'))->toBe(AttemptOutcome::Mfa);
    expect($signals->evaluationCalls)->toHaveCount(0);
    $code = app(TotpAuthenticator::class)->codeAt($factor->secret, time());
    expect($auth->completeMfa($request, $code))->toBeTrue()
        ->and($signals->evaluationCalls)->toHaveCount(1)
        ->and(Session::query()->where('user_id', $user->id)->count())->toBe(1);
});
