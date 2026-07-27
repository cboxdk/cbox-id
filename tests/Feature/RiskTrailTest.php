<?php

declare(strict_types=1);

use App\Models\RiskDecision;
use App\Platform\RiskGuard;
use App\Platform\RiskTrail;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Risk\Contracts\RiskScorer;
use Cbox\Risk\Enums\Outcome;
use Cbox\Risk\ValueObjects\RiskAssessment;
use Cbox\Risk\ValueObjects\RiskContext;
use Cbox\Risk\ValueObjects\SignalResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The breach check (HIBP) runs during signup — keep it offline.
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:0')]);
});

/** A request whose client IP is ours to choose, so the pseudonym can be checked. */
function trailRequestFrom(string $ip, string $path = '/signup'): Request
{
    return Request::create($path, 'POST', server: ['REMOTE_ADDR' => $ip]);
}

/** Pin the scorer to a fixed verdict so a flow's outcome is deterministic. */
function pinRiskVerdict(Outcome $outcome, float $score = 42.0): void
{
    app()->instance(RiskScorer::class, new class($outcome, $score) implements RiskScorer
    {
        public function __construct(private readonly Outcome $outcome, private readonly float $score) {}

        public function assess(RiskContext $context): RiskAssessment
        {
            return new RiskAssessment($this->score, $this->outcome, [
                new SignalResult('velocity', $this->score, 'pinned for test'),
            ]);
        }
    });
}

it('keeps the decision after the request that produced it', function (): void {
    config(['risk.mode' => 'monitor']);

    // A bot-shaped signup: the honeypot a human never fills is filled.
    Volt::test('auth.signup')
        ->set('organization', 'Acme')
        ->set('name', 'Definitely Human')
        ->set('email', 'bot@example.com')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->set('website', 'http://spam.example')
        ->call('register')
        ->assertHasNoErrors();

    // The whole point: the evidence is still there once the request is over, without
    // a live tail. Read it back through a FRESH query, not the object that wrote it.
    $decision = RiskDecision::query()->where('action', 'register')->sole();

    expect($decision->outcome)->toBe(Outcome::Reject)      // scored, though monitor let it through
        ->and($decision->mode)->toBe('monitor')            // ...and the row says so
        ->and($decision->score)->toBeGreaterThanOrEqual(100.0)
        ->and($decision->reasons)->toContain('honeypot field was filled (bot)')
        ->and($decision->signals)->toHaveKey('honeypot')   // the per-signal points, for re-weighting
        ->and($decision->email_domain)->toBe('example.com')
        ->and($decision->assessed_at)->not->toBeNull();
});

it('stores a keyed pseudonym of the client IP, never the address itself', function (): void {
    app(RiskGuard::class)->assess(trailRequestFrom('198.51.100.77'), 'register', 'Bot.Net@Spam.Example');

    $decision = RiskDecision::query()->sole();
    $stored = json_encode($decision->getAttributes(), JSON_THROW_ON_ERROR);

    expect($decision->ip_hash)
        ->not->toBe('198.51.100.77')
        ->toBe(hash_hmac('sha256', 'ip:198.51.100.77', (string) config('app.key')))
        // Nothing anywhere in the row — reasons and signals included — may carry it.
        ->and($stored)->not->toContain('198.51.100.77');
});

it('stores a keyed pseudonym of the email, never the address itself', function (): void {
    app(RiskGuard::class)->assess(trailRequestFrom('198.51.100.77'), 'register', 'Bot.Net@Spam.Example');

    $decision = RiskDecision::query()->sole();
    $stored = json_encode($decision->getAttributes(), JSON_THROW_ON_ERROR);

    expect($stored)
        ->not->toContain('Bot.Net')       // the local part is the identifying half
        ->and($stored)->not->toContain('bot.net')
        // The domain IS kept in the clear, deliberately — provider abuse patterns
        // are unreadable without it, and a domain names a provider, not a person.
        ->and($decision->email_domain)->toBe('spam.example')
        // Case and surrounding whitespace are folded, so the same address found by an
        // operator later always hashes to the same value.
        ->and($decision->email_hash)
        ->toBe(app(RiskTrail::class)->emailPseudonym('  bot.net@SPAM.example '));
});

it('records exactly one decision per login attempt, though both decision predicates run', function (): void {
    // Enforce + an elevated-but-not-blocking verdict is the path where login.blade.php
    // calls shouldBlock() AND shouldStepUp() on the same assessment. If either of them
    // wrote, one sign-in would count as two and every tuning number would be doubled.
    config(['risk.mode' => 'enforce']);
    pinRiskVerdict(Outcome::Flag);

    app(Subjects::class)->create('dana@example.com', 'Dana Reeves', 'a-strong-password-1234');

    Volt::test('auth.login')
        ->set('email', 'dana@example.com')
        ->set('password', 'a-strong-password-1234')
        ->set('identified', true)
        ->call('login')
        ->assertRedirect(route('dashboard'));

    expect(RiskDecision::query()->where('action', 'login')->count())->toBe(1);
});

it('writes nothing when the decision predicates are consulted on an existing assessment', function (): void {
    config(['risk.mode' => 'enforce']);
    pinRiskVerdict(Outcome::Reject, 99.0);

    $guard = app(RiskGuard::class);
    $assessment = $guard->assess(trailRequestFrom('203.0.113.4', '/login'), 'login');

    expect(RiskDecision::query()->count())->toBe(1);

    // The magic-link and passkey doors call shouldBlock() on a fresh assessment; the
    // password door calls both on one. Neither predicate may touch the trail.
    $guard->shouldBlock($assessment);
    $guard->shouldStepUp($assessment);

    expect(RiskDecision::query()->count())->toBe(1);
});

it('prunes decisions past the retention window and keeps the rest', function (): void {
    config(['cbox-id.risk_trail.retention_days' => 30]);

    seedDecision('login', Outcome::Allow, 0.0, now()->subDays(31));
    seedDecision('login', Outcome::Reject, 90.0, now()->subDays(29));

    Artisan::call('model:prune', ['--model' => [RiskDecision::class]]);

    expect(RiskDecision::query()->count())->toBe(1)
        ->and(RiskDecision::query()->sole()->outcome)->toBe(Outcome::Reject);
});

it('keeps the whole trail when retention is switched off', function (): void {
    config(['cbox-id.risk_trail.retention_days' => null]);

    seedDecision('login', Outcome::Allow, 0.0, now()->subYears(3));

    Artisan::call('model:prune', ['--model' => [RiskDecision::class]]);

    expect(RiskDecision::query()->count())->toBe(1);
});

it('answers the threshold-setting question the docs pose', function (): void {
    // The deliverable is the QUERY, so exercise the one docs/security/adaptive-risk.md
    // tells an operator to run: last week's signups, bucketed by score band.
    foreach ([2.0, 12.0, 34.0, 34.0, 88.0] as $score) {
        seedDecision('register', Outcome::Flag, $score, now()->subDays(3));
    }
    seedDecision('register', Outcome::Reject, 95.0, now()->subDays(40));  // outside the window
    seedDecision('login', Outcome::Reject, 95.0, now()->subDays(3));      // a different action

    $bands = RiskDecision::query()
        ->where('action', 'register')
        ->where('assessed_at', '>=', now()->subDays(7))
        // FLOOR rather than a cast: MySQL has no INTEGER cast target (it is SIGNED
        // there), so `CAST(… AS INTEGER)` is a syntax error on the engine this app
        // deploys to. The docs query this mirrors had the same defect.
        ->selectRaw('FLOOR(score / 10) * 10 AS band, COUNT(*) AS decisions')
        ->groupBy('band')
        ->orderBy('band')
        ->pluck('decisions', 'band')
        ->all();

    expect($bands)->toBe([0 => 1, 10 => 1, 30 => 2, 80 => 1]);
});

/** Insert a decision at a chosen instant — the trail is append-only, so no factory. */
function seedDecision(string $action, Outcome $outcome, float $score, DateTimeInterface $at): void
{
    DB::table('risk_decisions')->insert([
        'id' => (string) Str::ulid(),
        'environment_id' => 'env_test',
        'action' => $action,
        'mode' => 'monitor',
        'outcome' => $outcome->value,
        'score' => $score,
        'reasons' => json_encode([], JSON_THROW_ON_ERROR),
        'signals' => json_encode([], JSON_THROW_ON_ERROR),
        'ip_hash' => str_repeat('a', 64),
        'email_hash' => null,
        'email_domain' => null,
        'assessed_at' => $at,
    ]);
}
