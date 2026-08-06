<?php

declare(strict_types=1);

use App\Console\Commands\InstallCommand;
use App\Platform\Install\EnvFile;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/**
 * `cbox-id:install` — the controlled path from an empty database to a deployment
 * someone can sign in to.
 *
 * EVERY test here rebinds {@see EnvFile} to a scratch file. The real binding points at
 * the env file the app booted from, so a test that forgot this would rewrite the
 * developer's own `.env` — including `CBOX_ID_MULTI_TENANT`, which decides whether the
 * host bulkheads exist.
 */
beforeEach(function (): void {
    $this->envPath = tempnam(sys_get_temp_dir(), 'cbox-env-');
    file_put_contents((string) $this->envPath, "APP_ENV=testing\n");
    $this->app->instance(EnvFile::class, new EnvFile((string) $this->envPath));
});

afterEach(function (): void {
    if (is_string($this->envPath) && is_file($this->envPath)) {
        unlink($this->envPath);
    }
});

/**
 * @param  array<string, string|bool>  $options
 * @return array{0: int, 1: string}
 */
function runInstall(array $options = []): array
{
    $exit = Artisan::call('cbox-id:install', [
        '--no-interaction' => true,
        ...$options,
    ]);

    return [$exit, Artisan::output()];
}

it('is the command `cbox-id:install` actually runs', function (): void {
    // The framework package ships a command of the same name that stops at keys and
    // migrations. The application's registration wins — but "wins" is registration
    // ORDER, which is not something to leave to luck for the command that provisions the
    // platform root. If this ever flips, an operator following the documentation gets a
    // deployment with no operator in it and no error to explain why.
    expect(Artisan::all()['cbox-id:install'])->toBeInstanceOf(InstallCommand::class);
});

it('installs a single-tenant deployment whose operator can actually sign in', function (): void {
    [$exit] = runInstall([
        '--email' => 'root@acme.example',
        '--name' => 'Root Operator',
        '--password' => 'a-strong-unbreached-passphrase',
        '--environment' => 'Production',
    ]);

    expect($exit)->toBe(0);

    $root = Environment::query()->where('is_default', true)->first();

    expect($root)->not->toBeNull()
        ->and($root?->name)->toBe('Production')
        // Single-tenant creates no account, and the root belongs to nobody.
        ->and($root?->account_id)->toBeNull()
        ->and(Account::query()->count())->toBe(0);

    expect(app(PlatformOperators::class)->findByEmail('root@acme.example'))->not->toBeNull();

    // The real point of this test, and the reason it is not an assertion about rows: the
    // credential the install created has to WORK. The installer hashes nothing itself —
    // it hands the password to the PlatformOperators contract, and this is the check
    // every operator sign-in delegates to, both while the operator's credential is its
    // own row and once it is the subject's (the unification in flight upstream).
    $operator = app(PlatformOperators::class)->findByEmail('root@acme.example');

    expect($operator)->not->toBeNull()
        ->and(app(PlatformOperators::class)->verifyPassword((string) $operator?->id, 'a-strong-unbreached-passphrase'))->toBeTrue()
        // …and only that password.
        ->and(app(PlatformOperators::class)->verifyPassword((string) $operator?->id, 'not-the-password'))->toBeFalse();
});

it('refuses a platform that is already installed, and changes nothing', function (): void {
    platformRootEnvironment();
    app(PlatformOperators::class)->create('incumbent@acme.example', 'a-strong-unbreached-passphrase', 'Incumbent');

    [$exit, $output] = runInstall([
        '--email' => 'usurper@acme.example',
        '--password' => 'another-strong-unbreached-passphrase',
    ]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('already installed')
        ->and($output)->toContain('a platform operator exists')
        ->and(app(PlatformOperators::class)->findByEmail('usurper@acme.example'))->toBeNull();
});

it('refuses when a customer environment exists even with no operator at all', function (): void {
    // The dangerous case: an operator row can be deleted, so "no operator" is NOT the
    // same question as "nothing has claimed this platform".
    platformRootEnvironment();
    Environment::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'status' => 'active',
        'settings' => [],
    ]);

    [$exit, $output] = runInstall([
        '--email' => 'usurper@acme.example',
        '--password' => 'another-strong-unbreached-passphrase',
    ]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('an environment beyond a bare default exists')
        ->and(app(PlatformOperators::class)->exists())->toBeFalse();
});

it('adopts a bare default environment rather than minting a second platform root', function (): void {
    // A migrated-but-unclaimed deployment: one is_default environment, nothing else.
    $bare = platformRootEnvironment();

    [$exit] = runInstall([
        '--email' => 'root@acme.example',
        '--password' => 'a-strong-unbreached-passphrase',
    ]);

    expect($exit)->toBe(0)
        ->and(Environment::query()->where('is_default', true)->count())->toBe(1)
        ->and(Environment::query()->where('is_default', true)->value('id'))->toBe($bare->id);
});

it('fails loudly, and provisions nothing, when a required option is missing', function (): void {
    [$exit, $output] = runInstall();

    expect($exit)->toBe(1)
        ->and($output)->toContain('--email is required')
        ->and(app(PlatformOperators::class)->exists())->toBeFalse()
        ->and(Environment::query()->count())->toBe(0);
});

it('refuses the multi-tenant shape with nowhere for the account console to live', function (): void {
    [$exit, $output] = runInstall([
        '--email' => 'root@acme.example',
        '--password' => 'a-strong-unbreached-passphrase',
        '--multi-tenant' => true,
    ]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('--console-host')
        ->and(Environment::query()->count())->toBe(0);
});

it('installs the multi-tenant shape with an account and its own environment', function (): void {
    [$exit] = runInstall([
        '--email' => 'root@cboxid.com',
        '--name' => 'Root Operator',
        '--password' => 'a-strong-unbreached-passphrase',
        '--multi-tenant' => true,
        '--console-host' => 'cboxid.com',
        '--account' => 'Cbox',
        '--environment' => 'Production',
    ]);

    expect($exit)->toBe(0);

    $root = Environment::query()->where('is_default', true)->firstOrFail();
    $account = Account::query()->first();

    expect($account)->not->toBeNull()
        ->and($account?->name)->toBe('Cbox')
        // The platform root is nobody's: an account-owned root would put the platform's
        // own staff inside a customer's tenant.
        ->and($root->account_id)->toBeNull()
        // …and the account got an environment of its own, beside the root.
        ->and(environmentsOwnedBy($account?->id)->count())->toBe(1);

    // The shape is recorded where the bulkheads read it, not just applied in memory.
    $env = new EnvFile((string) $this->envPath);

    expect($env->get('CBOX_ID_MULTI_TENANT'))->toBe('true')
        ->and($env->get('CBOX_ID_CONSOLE_HOST'))->toBe('cboxid.com');
});

it('never echoes a password it was given', function (): void {
    [$exit, $output] = runInstall([
        '--email' => 'root@acme.example',
        '--password' => 'a-strong-unbreached-passphrase',
    ]);

    expect($exit)->toBe(0)
        ->and($output)->not->toContain('a-strong-unbreached-passphrase');
});

it('shows a generated password once, and that password works', function (): void {
    [$exit, $output] = runInstall(['--email' => 'root@acme.example']);

    expect($exit)->toBe(0)
        ->and($output)->toContain('Generated password');

    // Read it back out of the output the operator was shown, and use it. Anything less
    // proves only that SOMETHING was printed — not that it is the credential.
    $stripped = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);

    expect(preg_match('/Generated password for root@acme\.example:\s*\n[^\n]*?\s(\S{28})\s/', $stripped, $matches))->toBe(1);

    $operator = app(PlatformOperators::class)->findByEmail('root@acme.example');

    expect(app(PlatformOperators::class)->verifyPassword((string) $operator?->id, $matches[1]))->toBeTrue();
});
