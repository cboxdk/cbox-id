<?php

declare(strict_types=1);

use App\Platform\Console\EnvironmentConsolePath;
use App\Platform\Help\DocsLinks;
use App\Platform\Help\HelpTopic;
use App\Platform\Organizations\OrganizationLinks;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/*
 * An environment whose console has no address (in no project of an organization with an
 * address, {@see EnvironmentConsolePath::for()}) keeps its row: the projects pages say why
 * and offer the next step, and `/open/{environment}` lands back on the projects with the
 * same sentence. It used to link to `/open/{environment}`, which answered a bare 404.
 */
beforeEach(function (): void {
    multiTenantDeployment('cboxid.com');
});

/** The console path for nothing: every environment's console is unaddressable. */
function withoutConsoleAddresses(): void
{
    app()->instance(EnvironmentConsolePath::class, new readonly class(app(PlatformRoot::class), app(OrganizationLinks::class)) extends EnvironmentConsolePath
    {
        public function for(string $environmentId): ?string
        {
            return null;
        }
    });
}

it('keeps an environment with no console address on the projects pages, without a link to a 404', function (): void {
    ['subjectId' => $subjectId, 'project' => $project, 'environment' => $environment] = provisionAccount();
    signInAsMember($subjectId);

    // With an address: the link.
    $this->get('https://cboxid.com'.route('orgs.projects', [], false))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('projects.0.environments.0.openHref', app(EnvironmentConsolePath::class)->route($environment->id)));

    withoutConsoleAddresses();

    $this->get('https://cboxid.com'.route('orgs.projects', [], false))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('projects.0.environments.0.name', $environment->name)
            ->where('projects.0.environments.0.openHref', null));

    $this->get('https://cboxid.com'.route('orgs.projects.show', $project->id, false))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('environments.0.name', $environment->name)
            ->where('environments.0.openHref', null)
            // Where the Assistant is not set up, the row's next step is this guide.
            ->where('guideHref', app(DocsLinks::class)->url(HelpTopic::Projects))
            ->where('guideHref', fn (?string $href): bool => $href !== null));
});

it('sends /open for an environment with no console address back to the projects, saying why', function (): void {
    ['subjectId' => $subjectId, 'environment' => $environment] = provisionAccount();
    signInAsMember($subjectId);
    withoutConsoleAddresses();

    $this->get('https://cboxid.com/open/'.$environment->id)
        ->assertRedirect()
        ->assertSessionHas('error', __('console.environment_console_unresolved').'. '.__('console.environment_console_unresolved_detail'));

    expect((string) $this->get('https://cboxid.com/open/'.$environment->id)->headers->get('Location'))->toContain('/projects');
});
