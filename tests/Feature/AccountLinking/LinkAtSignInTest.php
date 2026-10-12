<?php

declare(strict_types=1);

use App\Platform\AccountLinking\AccountLinking;
use App\Platform\AccountLinking\FederationFailure;
use App\Platform\AccountLinking\LinkHold;
use App\Platform\AccountLinking\PendingLink;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\IdentityLink;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Cbox\Id\Otp\Contracts\OtpChannels;
use Cbox\Id\Otp\Testing\FakeOtpChannel;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Risk\Contracts\RiskScorer;
use Cbox\Risk\Enums\Outcome;
use Cbox\Risk\ValueObjects\RiskAssessment;
use Cbox\Risk\ValueObjects\RiskContext;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\RootGoogle;

/*
|--------------------------------------------------------------------------
| "LINK YOUR GOOGLE ACCOUNT TO sn@cbox.dk?" — the production report, end to end
|--------------------------------------------------------------------------
|
| The owner of cboxid.com had a password account, turned Google on for the platform's own
| environment, and signed in with Google as the same address. Google said yes; the product
| sent him to `/login` and showed nothing — no message, no way to prove the account was
| his. These walk that exact path against a faked Google and hold every turn of it: the
| link step, each way of proving the account, the landing (including the root's hand-off
| into a tenant's `/admin`), and every way the step must refuse.
*/

const LINK_HOST = RootGoogle::HOST;

beforeEach(function (): void {
    RootGoogle::deployment();

    // The owner: a password account in the platform root, like sn@cbox.dk.
    $this->account = provisionAccount('owner@acme.example');
    $this->ownerId = (string) $this->account['subjectId'];
    $this->tenantHost = $this->account['environment']->slug.'.'.LINK_HOST;

    RootGoogle::operatorSignedIn();
    $this->google = RootGoogle::turnOn();
    RootGoogle::signedOutBrowser();

    $this->mail = new FakeOtpChannel;
    app(OtpChannels::class)->register('email', $this->mail);
});

/** Where a redirect goes, as a path — the host is the root's either way. */
function linkPath(TestResponse $response): string
{
    return (string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH);
}

/** The link step's page, through the real route. */
function linkStep(): TestResponse
{
    return test()->get('https://'.LINK_HOST.'/login/link')->assertOk();
}

/** The sentence the sign-in page is showing, through the real route. */
function signInProblem(): ?string
{
    $page = test()->get('https://'.LINK_HOST.'/login')->assertOk();
    $flash = $page->inertiaPage()['flash'] ?? [];

    return is_array($flash) && is_string($flash['signInProblem'] ?? null) ? $flash['signInProblem'] : null;
}

function ownerIdentities(string $subjectId): array
{
    return app(PlatformRoot::class)->run(static fn (): array => IdentityLink::query()->where('user_id', $subjectId)->get()->all());
}

function stubRadar(Outcome $outcome): void
{
    config(['risk.mode' => 'enforce']);

    app()->instance(RiskScorer::class, new class($outcome) implements RiskScorer
    {
        public function __construct(private readonly Outcome $outcome) {}

        public function assess(RiskContext $context): RiskAssessment
        {
            return new RiskAssessment(99.0, $this->outcome, []);
        }
    });
}

it('asks the owner to prove the account, links Google on an emailed code, and signs him in', function (): void {
    $callback = RootGoogle::signIn('owner@acme.example');

    // Not signed in, not silently merged: the link step.
    expect(linkPath($callback))->toBe('/login/link')
        ->and(session(PlatformAuth::SESSION_KEY))->toBeNull()
        ->and(ownerIdentities($this->ownerId))->toBe([]);

    $step = linkStep();
    $step->assertInertia(fn (AssertableInertia $page) => $page
        ->component('auth/link-account')
        ->where('email', 'owner@acme.example')
        ->where('provider.label', $this->google->name)
        ->where('provider.key', 'google')
        // A password account with no second factor: the password, or a mailed code.
        ->where('factors', ['totp' => false, 'sms' => false, 'password' => true, 'email' => true])
        ->where('codeSent', false)
        ->where('challenged', false));

    $hold = (string) $step->inertiaProps('hold');

    // "Email me a code instead".
    $this->from('https://'.LINK_HOST.'/login/link')
        ->post('https://'.LINK_HOST.'/login/link/code', ['hold' => $hold, 'channel' => 'email'])
        ->assertRedirect();
    $this->mail->assertDelivered('owner@acme.example');

    $landed = $this->from('https://'.LINK_HOST.'/login/link')
        ->post('https://'.LINK_HOST.'/login/link', ['hold' => $hold, 'method' => 'email_code', 'answer' => (string) $this->mail->codeFor('owner@acme.example')]);

    // Signed in as the EXISTING account, on the dashboard (nowhere else to go).
    expect(linkPath($landed))->toBe('/dashboard');
    $session = Session::query()->withoutGlobalScopes()->find(session(PlatformAuth::SESSION_KEY));
    expect($session?->user_id)->toBe($this->ownerId);

    // Linked on Google's stable subject — never its address.
    $links = ownerIdentities($this->ownerId);
    expect($links)->toHaveCount(1)
        ->and($links[0]->subject)->toBe(RootGoogle::subjectFor('owner@acme.example'))
        ->and($links[0]->connection_id)->toBe($this->google->id);

    // On the trail, as the person, with the proof — and the inbox code confirmed the address.
    $entry = app(PlatformRoot::class)->run(static fn () => AuditEntry::query()->where('action', 'user.identity_linked')->sole());
    expect($entry->actor_id)->toBe($this->ownerId)
        ->and($entry->context['proof'] ?? null)->toBe('email_code')
        ->and($entry->context['provider'] ?? null)->toBe('google')
        ->and(app(PlatformRoot::class)->run(fn () => app(Subjects::class)->find($this->ownerId)?->emailVerified))->toBeTrue();

    // The hold is spent: a replayed answer finds nothing.
    expect(session(LinkHold::SESSION_KEY))->toBeNull();
});

it('links on the account\'s password, and the next Google sign-in goes straight in', function (): void {
    RootGoogle::signIn('owner@acme.example');
    $hold = (string) linkStep()->inertiaProps('hold');

    // A wrong password is a sentence on the field, and the hold survives it.
    $this->from('https://'.LINK_HOST.'/login/link')
        ->post('https://'.LINK_HOST.'/login/link', ['hold' => $hold, 'method' => 'password', 'answer' => 'not-the-password'])
        ->assertSessionHasErrors(['answer' => __('auth.link_account.wrong_password')]);

    $this->from('https://'.LINK_HOST.'/login/link')
        ->post('https://'.LINK_HOST.'/login/link', ['hold' => $hold, 'method' => 'password', 'answer' => 'a-strong-unbreached-passphrase'])
        ->assertRedirect();

    expect(ownerIdentities($this->ownerId))->toHaveCount(1);

    // Signed out, and back with Google: no step this time.
    RootGoogle::signedOutBrowser();
    $again = RootGoogle::signIn('owner@acme.example');

    expect(linkPath($again))->toBe('/dashboard');
    $session = Session::query()->withoutGlobalScopes()->find(session(PlatformAuth::SESSION_KEY));
    expect($session?->user_id)->toBe($this->ownerId);

    // And the identity says when it was last used — from laravel-id 1.26, which adds the column.
    if (Schema::hasColumn('identities', 'last_used_at')) {
        expect(ownerIdentities($this->ownerId)[0]->getAttribute('last_used_at'))->not->toBeNull();
    }
});

it('lands where the person was going: a tenant\'s /admin, through the root and its hand-off', function (): void {
    // The tenant's console, signed out: to the root's door, carrying the page.
    $open = (string) $this->get('https://'.$this->tenantHost.'/admin/users')->headers->get('Location');
    expect($open)->toContain('/open/'.$this->account['environment']->id);
    $this->get($open)->assertRedirect('https://'.LINK_HOST.'/login');

    RootGoogle::signIn('owner@acme.example');
    $hold = (string) linkStep()->inertiaProps('hold');

    $landed = $this->from('https://'.LINK_HOST.'/login/link')
        ->post('https://'.LINK_HOST.'/login/link', ['hold' => $hold, 'method' => 'password', 'answer' => 'a-strong-unbreached-passphrase']);

    // Back to the door they came through, with the page still on it…
    expect((string) $landed->headers->get('Location'))->toBe($open);

    // …and through the signed hand-off to the page they asked for.
    $handed = RootGoogle::handOff($open, $this->tenantHost);
    expect((string) $handed->headers->get('Location'))->toEndWith('/admin/users');

    $this->get('https://'.$this->tenantHost.'/admin/users')->assertOk();
    expect(app(EnvironmentAdminAuth::class)->subjectId())->toBe($this->ownerId);
});

it('asks an account with an authenticator for the authenticator, and nothing weaker', function (): void {
    $secret = app(PlatformRoot::class)->run(function (): string {
        $enrolment = app(Mfa::class)->enrollTotp($this->ownerId, 'owner@acme.example');
        confirmTotpForTest($this->ownerId, $enrolment->secret);

        return $enrolment->secret;
    });

    RootGoogle::signIn('owner@acme.example');
    $step = linkStep();

    expect($step->inertiaProps('factors'))->toBe(['totp' => true, 'sms' => false, 'password' => false, 'email' => false]);

    $hold = (string) $step->inertiaProps('hold');

    // The password is right, and it is not enough: a link skips the factor forever after.
    $this->from('https://'.LINK_HOST.'/login/link')
        ->post('https://'.LINK_HOST.'/login/link', ['hold' => $hold, 'method' => 'password', 'answer' => 'a-strong-unbreached-passphrase'])
        ->assertSessionHasErrors('answer');
    expect(ownerIdentities($this->ownerId))->toBe([]);

    $this->from('https://'.LINK_HOST.'/login/link')
        ->post('https://'.LINK_HOST.'/login/link', ['hold' => $hold, 'method' => 'totp', 'answer' => app(TotpAuthenticator::class)->codeAt($secret, time())])
        ->assertRedirect();

    expect(ownerIdentities($this->ownerId))->toHaveCount(1);
})->group('security');

it('refuses to link an address Google has not verified, and says so on the sign-in page', function (): void {
    $callback = RootGoogle::signIn('owner@acme.example', ['email_verified' => false]);

    expect(linkPath($callback))->toBe('/login')
        ->and(signInProblem())->toBe(FederationFailure::Unverified->message(['provider' => $this->google->name, 'email' => 'owner@acme.example']))
        ->and(ownerIdentities($this->ownerId))->toBe([])
        ->and(session(LinkHold::SESSION_KEY))->toBeNull();
})->group('security');

it('refuses a hold that was altered in the session store, and says the link expired', function (): void {
    RootGoogle::signIn('owner@acme.example');

    // Somebody rewrites the held account under the signature.
    $stored = session(LinkHold::SESSION_KEY);
    $stored['payload']['subject_id'] = 'somebody-else';
    session()->put(LinkHold::SESSION_KEY, $stored);

    $this->get('https://'.LINK_HOST.'/login/link')->assertRedirect();

    expect(signInProblem())->toBe(FederationFailure::LinkExpired->message())
        ->and(session(LinkHold::SESSION_KEY))->toBeNull();
})->group('security');

it('refuses an answer for a hold that has expired, or that is not the one on the page', function (): void {
    RootGoogle::signIn('owner@acme.example');
    $hold = (string) linkStep()->inertiaProps('hold');

    // A different hold id — a tab drawn for an earlier link.
    $this->from('https://'.LINK_HOST.'/login/link')
        ->post('https://'.LINK_HOST.'/login/link', ['hold' => str_repeat('0', 32), 'method' => 'password', 'answer' => 'a-strong-unbreached-passphrase'])
        ->assertRedirect();
    expect(ownerIdentities($this->ownerId))->toBe([]);

    // Ten minutes on, the right id and the right password link nothing.
    $this->travel(PendingLink::TTL_SECONDS)->seconds();

    $this->from('https://'.LINK_HOST.'/login/link')
        ->post('https://'.LINK_HOST.'/login/link', ['hold' => $hold, 'method' => 'password', 'answer' => 'a-strong-unbreached-passphrase'])
        ->assertRedirect();

    expect(ownerIdentities($this->ownerId))->toBe([])
        ->and(session(PlatformAuth::SESSION_KEY))->toBeNull()
        ->and(signInProblem())->toBe(FederationFailure::LinkExpired->message());
})->group('security');

it('drops the hold after five wrong answers, and links nothing', function (): void {
    RootGoogle::signIn('owner@acme.example');
    $hold = (string) linkStep()->inertiaProps('hold');

    foreach (range(1, PendingLink::MAX_FAILURES) as $attempt) {
        $this->from('https://'.LINK_HOST.'/login/link')
            ->post('https://'.LINK_HOST.'/login/link', ['hold' => $hold, 'method' => 'password', 'answer' => 'wrong-'.$attempt]);
    }

    expect(session(LinkHold::SESSION_KEY))->toBeNull()
        ->and(ownerIdentities($this->ownerId))->toBe([])
        ->and(signInProblem())->toBe(FederationFailure::LinkAttemptsExhausted->message(['provider' => $this->google->name]));
})->group('security');

it('says nothing was linked when the person answers "Don\'t link"', function (): void {
    RootGoogle::signIn('owner@acme.example');
    $hold = (string) linkStep()->inertiaProps('hold');

    $this->post('https://'.LINK_HOST.'/login/link/cancel', ['hold' => $hold])
        ->assertRedirect()
        ->assertSessionHas('status', __('auth.link_account.cancelled'));

    expect(session(LinkHold::SESSION_KEY))->toBeNull()
        ->and(ownerIdentities($this->ownerId))->toBe([]);
});

it('ends the attempt on a Radar block, with the generic sentence', function (): void {
    stubRadar(Outcome::Reject);

    $callback = RootGoogle::signIn('owner@acme.example');

    expect(linkPath($callback))->toBe('/login')
        ->and(signInProblem())->toBe(FederationFailure::Refused->message())
        ->and(session(LinkHold::SESSION_KEY))->toBeNull();
})->group('security');

it('takes the password away on a Radar challenge, leaving a code from the inbox', function (): void {
    stubRadar(Outcome::StepUp);

    RootGoogle::signIn('owner@acme.example');
    $step = linkStep();

    expect($step->inertiaProps('challenged'))->toBeTrue()
        ->and($step->inertiaProps('factors'))->toBe(['totp' => false, 'sms' => false, 'password' => false, 'email' => true])
        // The only proof left is the mailed code, so it was sent without being asked for.
        ->and($step->inertiaProps('codeSent'))->toBeTrue();

    $this->mail->assertDelivered('owner@acme.example');

    $hold = (string) $step->inertiaProps('hold');

    $this->from('https://'.LINK_HOST.'/login/link')
        ->post('https://'.LINK_HOST.'/login/link', ['hold' => $hold, 'method' => 'password', 'answer' => 'a-strong-unbreached-passphrase'])
        ->assertSessionHasErrors('answer');

    expect(ownerIdentities($this->ownerId))->toBe([]);
})->group('security');

it('sends the code to the account\'s own address, under its own purpose', function (): void {
    RootGoogle::signIn('owner@acme.example');
    $hold = (string) linkStep()->inertiaProps('hold');

    $this->from('https://'.LINK_HOST.'/login/link')
        ->post('https://'.LINK_HOST.'/login/link/code', ['hold' => $hold, 'channel' => 'email']);

    expect($this->mail->latest()?->recipient)->toBe('owner@acme.example')
        ->and($this->mail->latest()?->purpose)->toBe(AccountLinking::OTP_PURPOSE);
});
