<?php

declare(strict_types=1);

use App\Platform\Onboarding\EnvironmentChecklist;
use App\Platform\Onboarding\EnvironmentStep;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\SignInProviders;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * ENTERPRISE SSO LISTS ENTERPRISE CONNECTIONS. A social provider is stored as a federation
 * connection as well — the catalogue key in `provider` is the difference — and the list read
 * the table unfiltered, so an environment's Google and GitHub buttons appeared under
 * Authentication › Enterprise SSO as connections, the home page counted them, and turning
 * Google on ticked "Connect enterprise SSO" on the setup checklist. They are Social login's
 * rows; this page, its count and its checklist step are about a company's identity provider.
 */
it('leaves social providers out of Enterprise SSO, its count and its checklist step', function (): void {
    actAsEnvironmentAdminOfATenant();

    $acme = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-sso-list'));
    $providers = app(SignInProviders::class);
    $connections = app(Connections::class);

    // The environment's GitHub button, and Acme's own Google.
    foreach ([[null, 'github'], [$acme->id, 'google']] as [$owner, $key]) {
        $social = $providers->create($owner, $key, ConnectionType::OAuth2, ucfirst($key), ['provider' => $key, 'client_id' => 'x', 'client_secret' => 'y']);
        $connections->activate($owner, $social->id);
    }

    expect(app(EnvironmentChecklist::class)->progress(null)[EnvironmentStep::ConnectSso->value])->toBeFalse();

    $okta = $connections->create($acme->id, ConnectionType::Saml, 'Okta', []);

    $this->get('/admin/single-sign-on')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('connections', 1)
        ->where('connections.0.id', $okta->id));

    $this->get('/admin/organizations/'.$acme->id.'/single-sign-on')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('connections', 1)
        ->where('connections.0.name', 'Okta'));

    $this->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('stats', fn ($stats): bool => collect($stats)->firstWhere('label', 'Enterprise SSO')['count'] === 1));

    expect(app(EnvironmentChecklist::class)->progress(null)[EnvironmentStep::ConnectSso->value])->toBeTrue();
});
