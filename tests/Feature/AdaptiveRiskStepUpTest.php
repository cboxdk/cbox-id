<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\MagicLink;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Otp\Contracts\OtpChannels;
use Cbox\Id\Otp\Testing\FakeOtpChannel;
use Cbox\Risk\Contracts\RiskScorer;
use Cbox\Risk\Enums\Outcome;
use Cbox\Risk\ValueObjects\RiskAssessment;
use Cbox\Risk\ValueObjects\RiskContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/** Force the risk scorer to a fixed outcome so the flow is deterministic. */
function stubRiskOutcome(Outcome $outcome): void
{
    app()->instance(RiskScorer::class, new class($outcome) implements RiskScorer
    {
        public function __construct(private readonly Outcome $outcome) {}

        public function assess(RiskContext $context): RiskAssessment
        {
            return new RiskAssessment(99.0, $this->outcome, []);
        }
    });
}

function makePasswordUser(string $email): string
{
    return app(Subjects::class)->create($email, 'Test User', 'a-strong-password-1234')->id;
}

it('steps up with an emailed code on an elevated-risk password login', function (): void {
    config(['risk.mode' => 'enforce']);
    stubRiskOutcome(Outcome::StepUp);
    makePasswordUser('risky@example.com');

    $channel = new FakeOtpChannel;
    app(OtpChannels::class)->register('email', $channel);

    Volt::test('auth.login')
        ->set('email', 'risky@example.com')
        ->set('password', 'a-strong-password-1234')
        ->set('identified', true)
        ->call('login')
        ->assertRedirect(route('login.step-up')); // interstitial, not the dashboard

    // A step-up code was emailed, and no full session was established yet.
    $channel->assertDelivered('risky@example.com');
    expect(session()->has('cbox.session'))->toBeFalse();
});

it('establishes the session (amr: otp) once the step-up code is verified', function (): void {
    config(['risk.mode' => 'enforce']);
    stubRiskOutcome(Outcome::StepUp);
    $subjectId = makePasswordUser('risky2@example.com');

    $channel = new FakeOtpChannel;
    app(OtpChannels::class)->register('email', $channel);

    Volt::test('auth.login')
        ->set('email', 'risky2@example.com')->set('password', 'a-strong-password-1234')->set('identified', true)
        ->call('login')->assertRedirect(route('login.step-up'));

    $code = (string) $channel->codeFor('risky2@example.com');

    Volt::test('auth.otp-step-up')
        ->set('code', $code)
        ->call('verify')
        ->assertRedirect(route('dashboard'));

    $session = Session::query()->where('user_id', $subjectId)->latest()->first();
    expect($session)->not->toBeNull()
        ->and($session->amr)->toContain('otp')
        ->and($session->amr)->toContain('pwd');
});

it('rejects a wrong step-up code', function (): void {
    config(['risk.mode' => 'enforce']);
    stubRiskOutcome(Outcome::StepUp);
    makePasswordUser('risky3@example.com');

    $channel = new FakeOtpChannel;
    app(OtpChannels::class)->register('email', $channel);

    Volt::test('auth.login')
        ->set('email', 'risky3@example.com')->set('password', 'a-strong-password-1234')->set('identified', true)
        ->call('login');

    Volt::test('auth.otp-step-up')
        ->set('code', '000000')
        ->call('verify')
        ->assertHasErrors('code');

    expect(session()->has('cbox.session'))->toBeFalse();
});

it('resends a fresh step-up code', function (): void {
    config(['risk.mode' => 'enforce']);
    stubRiskOutcome(Outcome::StepUp);
    makePasswordUser('resend@example.com');

    $channel = new FakeOtpChannel;
    app(OtpChannels::class)->register('email', $channel);

    Volt::test('auth.login')
        ->set('email', 'resend@example.com')->set('password', 'a-strong-password-1234')->set('identified', true)
        ->call('login')->assertRedirect(route('login.step-up'));

    Volt::test('auth.otp-step-up')->call('resend')->assertHasNoErrors();

    $channel->assertDeliveredCount(2); // original + resend
});

it('clears a dangling step-up handle once a full session is established (hygiene)', function (): void {
    config(['risk.mode' => 'enforce']);
    stubRiskOutcome(Outcome::StepUp);
    makePasswordUser('victim@example.com');

    $channel = new FakeOtpChannel;
    app(OtpChannels::class)->register('email', $channel);

    // A risky login for the victim leaves an otp_pending handle in the session.
    Volt::test('auth.login')
        ->set('email', 'victim@example.com')->set('password', 'a-strong-password-1234')->set('identified', true)
        ->call('login')->assertRedirect(route('login.step-up'));

    expect(app(PlatformAuth::class)->pendingOtpStepUp(request()))->not->toBeNull();

    // A different, low-risk login in the same browser session establishes cleanly...
    makePasswordUser('other@example.com');
    stubRiskOutcome(Outcome::Allow);
    Volt::test('auth.login')
        ->set('email', 'other@example.com')->set('password', 'a-strong-password-1234')->set('identified', true)
        ->call('login')->assertRedirect(route('dashboard'));

    // ...and the victim's dangling step-up handle is gone.
    expect(app(PlatformAuth::class)->pendingOtpStepUp(request()))->toBeNull();
});

it('does not step up in monitor mode (scores but does not act)', function (): void {
    config(['risk.mode' => 'monitor']);
    stubRiskOutcome(Outcome::StepUp);
    makePasswordUser('mon@example.com');

    Volt::test('auth.login')
        ->set('email', 'mon@example.com')->set('password', 'a-strong-password-1234')->set('identified', true)
        ->call('login')
        ->assertRedirect(route('dashboard')); // straight in — only observed
});

it('hard-blocks a Reject password login under enforcement', function (): void {
    config(['risk.mode' => 'enforce']);
    stubRiskOutcome(Outcome::Reject);
    makePasswordUser('rej@example.com');

    Volt::test('auth.login')
        ->set('email', 'rej@example.com')->set('password', 'a-strong-password-1234')->set('identified', true)
        ->call('login')
        ->assertHasErrors('email');
});

it('hard-blocks a Reject magic-link redemption before consuming the token', function (): void {
    config(['risk.mode' => 'enforce']);
    stubRiskOutcome(Outcome::Reject);
    makePasswordUser('mlink@example.com');
    $token = app(MagicLink::class)->request('mlink@example.com');

    // Blocked → bounced to login (a successful redeem would land on the dashboard).
    $this->get(route('magic.redeem', $token))->assertRedirect(route('login'));
    expect(session()->has('cbox.session'))->toBeFalse();
});

it('hard-blocks a Reject passkey login before the ceremony', function (): void {
    config(['risk.mode' => 'enforce']);
    stubRiskOutcome(Outcome::Reject);

    // The risk gate fires first — before the (missing) ceremony would be checked.
    $this->postJson(route('passkeys.login'), ['id' => 'anything'])
        ->assertJsonPath('error', 'We could not process this request. Please try again later.');
});
