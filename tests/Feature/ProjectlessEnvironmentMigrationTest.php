<?php

declare(strict_types=1);

use App\Platform\Console\EnvironmentConsolePath;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * THE ENVIRONMENT CONSOLE'S ADDRESS NAMES A PROJECT, so every environment but the platform
 * root's needs one. The data migration attaches an environment that has none to a project
 * of the one organization that grants it, and stops the deploy, writing nothing, on any it
 * cannot place: an environment left without a project would lose its console unseen.
 */
function projectlessEnvironment(string $slug): Environment
{
    return app(PlatformRoot::class)->run(fn (): Environment => Environment::query()->create([
        'project_id' => null,
        'name' => ucfirst($slug),
        'slug' => $slug,
        'type' => EnvironmentType::Sandbox,
        'status' => EnvironmentStatus::Active,
    ]));
}

function attachProjectlessEnvironments(): void
{
    (require database_path('migrations/2026_11_10_200000_attach_projectless_environments_to_a_project.php'))->up();
}

it('leaves no environment but the platform root\'s without a project, and gives each a console address', function (): void {
    platformRootEnvironment();
    $account = provisionAccount();

    $orphan = projectlessEnvironment('legacy-staging');

    // A grant from before projects: written as the row it is, since today's API grants
    // only environments the organization's projects own.
    DB::table('membership_environments')->insert([
        'membership_id' => $account['member']->id,
        'environment_id' => $orphan->id,
    ]);

    expect(app(EnvironmentConsolePath::class)->for($orphan->id))->toBeNull();

    attachProjectlessEnvironments();

    expect($orphan->refresh()->project_id)->toBe($account['project']->id)
        ->and(Environment::query()->whereNull('project_id')->where('is_default', false)->count())->toBe(0)
        // The platform root's keeps none: it lives at /admin/environment.
        ->and(Environment::query()->where('is_default', true)->value('project_id'))->toBeNull();

    $path = app(EnvironmentConsolePath::class)->for($orphan->id);

    expect($path)->toBe('orgs/'.$account['organization']->slug.'/projects/'.$account['project']->slug.'/environments/legacy-staging')
        ->and(app(EnvironmentConsolePath::class)->resolve((string) $path)?->id)->toBe($orphan->id);
});

it('does not guess: an environment no single organization grants stops the migration, before anything is written', function (): void {
    platformRootEnvironment();
    $account = provisionAccount();
    $unclaimed = projectlessEnvironment('nobodys');
    $placeable = projectlessEnvironment('placeable');
    DB::table('membership_environments')->insert(['membership_id' => $account['member']->id, 'environment_id' => $placeable->id]);

    expect(fn () => attachProjectlessEnvironments())->toThrow(RuntimeException::class, $unclaimed->id.' (nobodys, granted by 0 organizations)');

    expect($unclaimed->refresh()->project_id)->toBeNull()
        // All or nothing: the deploy is rerun once a person has decided.
        ->and($placeable->refresh()->project_id)->toBeNull();
});

it('does not guess between two organizations that both grant it', function (): void {
    platformRootEnvironment();
    $acme = provisionAccount();
    $globex = provisionAccount('owner@globex.example');
    $shared = projectlessEnvironment('shared-staging');

    foreach ([$acme, $globex] as $account) {
        DB::table('membership_environments')->insert(['membership_id' => $account['member']->id, 'environment_id' => $shared->id]);
    }

    expect(fn () => attachProjectlessEnvironments())->toThrow(RuntimeException::class, $shared->id.' (shared-staging, granted by 2 organizations)');

    expect($shared->refresh()->project_id)->toBeNull();
});

it('lets only an active membership speak for its organization', function (): void {
    platformRootEnvironment();
    $acme = provisionAccount();
    $globex = provisionAccount('owner@globex.example');
    $orphan = projectlessEnvironment('legacy-qa');

    foreach ([$acme, $globex] as $account) {
        DB::table('membership_environments')->insert(['membership_id' => $account['member']->id, 'environment_id' => $orphan->id]);
    }

    // Globex's grant hangs off a membership that is no longer in force: Acme owns it alone.
    DB::table('memberships')->where('id', $globex['member']->id)->update(['status' => MembershipStatus::Suspended->value]);

    attachProjectlessEnvironments();

    expect($orphan->refresh()->project_id)->toBe($acme['project']->id);
});
