<?php

declare(strict_types=1);

use App\Platform\Console\EnvironmentConsolePath;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/**
 * THE ENVIRONMENT CONSOLE'S ADDRESS NAMES A PROJECT, so every environment but the platform
 * root's needs one. The data migration attaches an environment that has none to a project
 * of the one organization that grants it, and leaves anything it cannot place alone, loudly.
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

it('does not guess: an environment no single organization grants is left as it is, and logged', function (): void {
    platformRootEnvironment();
    provisionAccount();
    $unclaimed = projectlessEnvironment('nobodys');

    Log::spy();

    attachProjectlessEnvironments();

    expect($unclaimed->refresh()->project_id)->toBeNull();
    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => ($context['environment'] ?? null) === $unclaimed->id,
    )->once();
});
