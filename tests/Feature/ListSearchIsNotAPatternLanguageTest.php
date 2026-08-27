<?php

declare(strict_types=1);

use App\Platform\Console\LikeTerm;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

/**
 * A SEARCH BOX IS NOT A PATTERN LANGUAGE.
 *
 * `LIKE` reads `%` as "any run of characters" and `_` as "any one character". Four console
 * lists interpolated the typed term straight into one, so every search was a wildcard
 * expression somebody wrote by accident: `c_ntoso` matched Contoso, an address containing
 * an underscore — which is most machine-generated ones — matched addresses it has nothing
 * to do with, and a lone `%` listed the whole table.
 *
 * On the tenant lists that is a correctness bug. On the OPERATOR plane it is more than
 * that: those lists span every customer on the deployment, so a term that widens instead
 * of narrowing shows rows from tenants the search was never about.
 *
 * The escaping lives in one place ({@see LikeTerm}) and this holds
 * every list that uses it — including the `%` case, which is the one that turns a search
 * into "select everything".
 */
it('does not read an underscore in the platform environment search as a wildcard', function (): void {
    actAsOperator();
    platformRootEnvironment();

    Environment::query()->create(['name' => 'Contoso Staging', 'slug' => 'contoso-staging', 'status' => 'active']);

    $names = fn (string $term): Collection => collect(
        platformEnvironments(['q' => $term])['environments']
    )->pluck('name');

    // The positive control first: without it, a search broken to match NOTHING would
    // satisfy every negative assertion below.
    expect($names('contoso'))->toContain('Contoso Staging')
        ->and($names('c_ntoso'))->not->toContain('Contoso Staging')
        ->and($names('%'))->not->toContain('Contoso Staging');
})->group('security');

it('does not read a wildcard in the operator roster search as a wildcard', function (): void {
    actAsOperator();

    app(PlatformOperators::class)->create('ada_lovelace@platform.test', 'a-strong-unbreached-passphrase', 'Ada Lovelace');

    $emails = fn (string $term): Collection => collect(
        (array) test()->get(route('platform.operators', ['q' => $term]))->assertOk()->inertiaProps('operators')
    )->pluck('email');

    expect($emails('ada_lovelace'))->toContain('ada_lovelace@platform.test')
        // A different address that the wildcard reading would have matched — the point is
        // not that the term is refused but that it is taken literally.
        ->and($emails('ada%lovelace'))->not->toContain('ada_lovelace@platform.test')
        ->and($emails('%'))->not->toContain('ada_lovelace@platform.test');
})->group('security');

it('does not read a wildcard in the environment user search as a wildcard', function (): void {
    crudSetup();

    app(Subjects::class)->create('jane_doe@acme.example', 'Jane Doe', 'a-strong-unbreached-passphrase');

    $emails = fn (string $term): Collection => collect(
        (array) test()->get(route('environment.users', ['q' => $term]))->assertOk()->inertiaProps('users')
    )->pluck('email');

    expect($emails('jane_doe'))->toContain('jane_doe@acme.example')
        ->and($emails('jane%doe'))->not->toContain('jane_doe@acme.example')
        ->and($emails('%'))->not->toContain('jane_doe@acme.example');
})->group('security');
