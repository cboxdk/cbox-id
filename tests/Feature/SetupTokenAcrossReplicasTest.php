<?php

declare(strict_types=1);

use App\Platform\Install\Contracts\SetupTokens;
use App\Platform\Install\DatabaseSetupTokens;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The first-run setup token on a deployment with more than one replica.
|--------------------------------------------------------------------------
|
| It used to live on one pod's disk, so with two web replicas the look at `/first-run`
| and the claim could land on different pods and a fresh install failed to claim at
| random. It lives in the shared database now — hashed, single use, with an expiry —
| and these are the tests that would have been red.
*/

/**
 * Become "the other pod": a process with none of this one's local state. Its own disk is
 * empty, its in-process cache is empty, and its token store is built from scratch — all
 * it shares with the first pod is the database, which is all a second replica shares.
 */
function servedByAnotherReplica(): void
{
    Storage::fake('local');
    Cache::flush();
    app()->forgetInstance(SetupTokens::class);
}

/** Mint a token the way an operator does — `cbox-id:setup-token` — and return what it printed. */
function setupTokenFromCommand(): string
{
    expect(Artisan::call('cbox-id:setup-token'))->toBe(0);

    preg_match('/^[0-9a-f]{64}$/m', Artisan::output(), $match);

    expect($match)->not->toBeEmpty('cbox-id:setup-token printed no token');

    return $match[0];
}

it('accepts a token minted on one replica at another', function (): void {
    // Pod A: the operator runs the command in whichever pod `kubectl exec` picked.
    $token = setupTokenFromCommand();

    // Pod B answers the browser.
    servedByAnotherReplica();

    $this->get('/first-run')->assertOk();

    claimDeployment(['token' => $token])->assertSessionHasNoErrors()->assertRedirect();

    expect(app(PlatformOperators::class)->findByEmail('root@acme.example'))->not->toBeNull();
});

it('keeps one token across replicas arming at the same time', function (): void {
    // Two pods serving their first look at once: the second must neither add a row nor
    // replace the first one's token.
    $this->get('/first-run')->assertOk();
    $first = DB::table('setup_tokens')->value('token_hash');

    servedByAnotherReplica();
    $this->get('/first-run')->assertOk();

    expect(DB::table('setup_tokens')->count())->toBe(1)
        ->and(DB::table('setup_tokens')->value('token_hash'))->toBe($first);
});

it('announces a token once, however many replicas arm it', function (): void {
    $log = Mockery::spy(LoggerInterface::class);

    (new DatabaseSetupTokens($log, 60))->arm();
    (new DatabaseSetupTokens($log, 60))->arm();

    // The default leaves the value out of the log entirely.
    $log->shouldHaveReceived('warning')->once()->with(Mockery::type('string'), []);
});

it('puts the value in the log only where the deployment opted in, and that value claims', function (): void {
    $logged = null;
    $log = Mockery::mock(LoggerInterface::class);
    $log->shouldReceive('warning')->once()->andReturnUsing(function (string $message, array $context) use (&$logged): void {
        $logged = $context['setup_token'] ?? null;
    });

    (new DatabaseSetupTokens($log, 60, logToken: true))->arm();

    expect($logged)->toBeString()
        ->and(app(SetupTokens::class)->matches((string) $logged))->toBeTrue();
});

it('stores only a hash of the token', function (): void {
    $token = app(SetupTokens::class)->rotate();

    $row = (array) DB::table('setup_tokens')->first();

    expect($row['token_hash'])->toBe(hash('sha256', $token))
        ->and(implode('|', array_map(strval(...), $row)))->not->toContain($token);
});

it('accepts the token and nothing near it', function (): void {
    $tokens = app(SetupTokens::class);
    $token = $tokens->rotate();
    $hash = (string) DB::table('setup_tokens')->value('token_hash');

    // The exact value, with the whitespace a paste brings along, is the token.
    expect($tokens->matches($token))->toBeTrue()
        ->and($tokens->matches("  {$token}\n"))->toBeTrue();

    $nearMisses = [
        'a prefix' => substr($token, 0, 63),
        'one character off' => substr($token, 0, 63).($token[63] === 'a' ? 'b' : 'a'),
        'upper case' => strtoupper($token),
        // The stored digest is not a credential: what is compared is the hash of the guess.
        'the stored hash' => $hash,
        'empty' => '',
    ];

    foreach ($nearMisses as $label => $candidate) {
        expect($tokens->matches($candidate))->toBeFalse("accepted {$label}");
    }
});

it('spends the token on a successful claim, for every replica', function (): void {
    $token = app(SetupTokens::class)->rotate();

    claimDeployment(['token' => $token])->assertSessionHasNoErrors();

    servedByAnotherReplica();

    expect(app(SetupTokens::class)->matches($token))->toBeFalse()
        ->and(DB::table('setup_tokens')->count())->toBe(0);
});

it('retires the previous token when a new one is printed', function (): void {
    $first = setupTokenFromCommand();
    $second = setupTokenFromCommand();

    expect($second)->not->toBe($first)
        ->and(app(SetupTokens::class)->matches($first))->toBeFalse()
        ->and(app(SetupTokens::class)->matches($second))->toBeTrue();
});

it('expires an unclaimed token, and re-arms on the next look', function (): void {
    config(['cbox-id.setup_token_ttl_minutes' => 30]);
    app()->forgetInstance(SetupTokens::class);

    $token = app(SetupTokens::class)->rotate();
    $hash = DB::table('setup_tokens')->value('token_hash');

    $this->travel(29)->minutes();
    expect(app(SetupTokens::class)->matches($token))->toBeTrue();

    $this->travel(2)->minutes();
    expect(app(SetupTokens::class)->matches($token))->toBeFalse()
        ->and(app(SetupTokens::class)->armed())->toBeFalse();

    claimDeployment(['token' => $token])->assertSessionHasErrors('token');

    expect(app(PlatformOperators::class)->exists())->toBeFalse();

    // Expiry never locks the operator out: the next look arms a fresh token.
    $this->get('/first-run')->assertOk();

    expect(app(SetupTokens::class)->armed())->toBeTrue()
        ->and(DB::table('setup_tokens')->value('token_hash'))->not->toBe($hash);
});

it('prints no token for a deployment that has already been claimed', function (): void {
    app(SetupTokens::class)->rotate();
    app(PlatformOperators::class)->create('root@acme.example', 'a-strong-unbreached-passphrase', 'Root');

    expect(Artisan::call('cbox-id:setup-token'))->toBe(0)
        ->and(Artisan::output())->toContain('already been claimed')
        ->and(Artisan::output())->not->toMatch('/[0-9a-f]{64}/')
        // …and a leftover token is spent rather than left live for a door that is gone.
        ->and(DB::table('setup_tokens')->count())->toBe(0);
});
