<?php

declare(strict_types=1);

use App\Platform\Install\Contracts\PlatformInstaller;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * "Is this platform still empty?" — the question the install command refuses on and the
 * first-run screen exists on. One answer, so the two cannot disagree.
 */
it('is empty when nothing has claimed the deployment', function (): void {
    expect(app(PlatformInstaller::class)->isEmpty())->toBeTrue()
        ->and(app(PlatformInstaller::class)->occupancy()->describe())->toBe('the platform is empty');
});

it('is empty with only a bare default environment', function (): void {
    // A migrated image, or a deployment the framework's own bootstrap got halfway
    // through. Refusing here would leave it with no supported way forward at all.
    platformRootEnvironment();

    expect(app(PlatformInstaller::class)->isEmpty())->toBeTrue();
});

it('is claimed by anything that only an administered platform produces', function (string $occupant, string $found): void {
    match ($occupant) {
        'operator' => app(PlatformOperators::class)->create('op@platform.test', 'a-strong-unbreached-passphrase', 'Op'),
        'subject' => app(Subjects::class)->create('someone@acme.test', 'Someone', 'a-strong-unbreached-passphrase'),
        'organization' => app(Organizations::class)->create(new NewOrganization('Acme', 'acme-occupied')),
        'environment' => Environment::query()->create([
            'name' => 'Acme',
            'slug' => 'acme-env',
            'status' => 'active',
            'settings' => [],
        ]),
        default => null,
    };

    $installer = app(PlatformInstaller::class);

    expect($installer->isEmpty())->toBeFalse()
        ->and($installer->occupancy()->describe())->toContain($found);
})->with([
    'an operator' => ['operator', 'a platform operator exists'],
    'a subject' => ['subject', 'a user identity exists'],
    'an organization' => ['organization', 'an organization exists'],
    'an environment somebody owns' => ['environment', 'an environment beyond a bare default exists'],
]);

it('treats an un-migrated deployment as empty rather than as broken', function (): void {
    // The most likely way to meet the first-run screen: a container that has never run
    // its migrations. Answering "not empty" would 404 the one page that could help, and
    // failing outright would 500 every page instead of explaining anything.
    Schema::drop('platform_operators');

    $installer = app(PlatformInstaller::class);

    expect($installer->isEmpty())->toBeTrue()
        // …but not installable yet, which is what lets the screen say so.
        ->and($installer->ready())->toBeFalse();
});

it('treats an unreachable database as CLAIMED, so an outage never opens the setup door', function (): void {
    config(['database.connections.unreachable' => [
        'driver' => 'sqlite',
        'database' => '/no/such/directory/cbox.sqlite',
        'prefix' => '',
    ]]);

    $installer = app(PlatformInstaller::class);
    $previous = config('database.default');

    DB::setDefaultConnection('unreachable');

    try {
        expect($installer->isEmpty())->toBeFalse()
            ->and($installer->occupancy()->describe())->toContain('could not be read');
    } finally {
        DB::setDefaultConnection(is_string($previous) ? $previous : 'sqlite');
    }
});
