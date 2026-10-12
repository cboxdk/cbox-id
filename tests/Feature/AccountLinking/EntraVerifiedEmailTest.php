<?php

declare(strict_types=1);

use App\Platform\AccountLinking\EntraVerifiedEmail;
use App\Platform\AccountLinking\FederationFailure;
use App\Platform\AccountLinking\VouchedEmail;
use App\Platform\PlatformAuth;
use App\Platform\Social\ProviderSetupSteps;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\ProviderCatalog;
use Cbox\Id\Federation\Support\OrganizationVouchedEmail;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Illuminate\Testing\TestResponse;
use Tests\Support\RootEntra;
use Tests\Support\RootGoogle;

/*
|--------------------------------------------------------------------------
| Microsoft Entra ID vouches for an address with `xms_edov`, not `email_verified`
|--------------------------------------------------------------------------
| Entra never sends `email_verified`, so the link step refused every Entra sign-in whose
| address already had an account. Its documented signal is the optional `xms_edov` claim
| ("email domain owner verified"): the address is in a domain the user's own tenant has
| verified. These sign in through a faked Entra tenant with the claim true, false, absent
| and malformed, and hold that only `true` opens the link step — and that an
| organization's own Entra connection gets no further with it than the framework would
| let `email_verified` get: inside a domain that organization has proven.
*/

beforeEach(function (): void {
    RootGoogle::deployment();
    RootEntra::discovery();

    provisionAccount('owner@acme.example');

    RootGoogle::operatorSignedIn();
    $this->entra = RootEntra::turnOn();
    RootGoogle::signedOutBrowser();
});

function entraLanding(TestResponse $response): string
{
    return (string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH);
}

function entraSignInProblem(): ?string
{
    $flash = test()->get('https://'.RootGoogle::HOST.'/login')->assertOk()->inertiaPage()['flash'] ?? [];

    return is_array($flash) && is_string($flash['signInProblem'] ?? null) ? $flash['signInProblem'] : null;
}

it('offers the link step when Entra says the address’s domain is verified (xms_edov: true)', function (): void {
    $callback = RootEntra::signIn('owner@acme.example', ['xms_edov' => true]);

    expect(entraLanding($callback))->toBe('/login/link')
        ->and(session(PlatformAuth::SESSION_KEY))->toBeNull();

    test()->get('https://'.RootGoogle::HOST.'/login/link')->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/link-account')
            ->where('email', 'owner@acme.example')
            ->where('provider.key', 'microsoft'));
});

it('keeps refusing, with the same sentence, when Entra does not vouch', function (array $claims): void {
    $callback = RootEntra::signIn('owner@acme.example', $claims);

    expect(entraLanding($callback))->toBe('/login')
        ->and(entraSignInProblem())->toBe(FederationFailure::Unverified->message([
            'provider' => $this->entra->name,
            'email' => 'owner@acme.example',
        ]));
})->with([
    'no xms_edov (the app registration did not ask for it)' => [[]],
    'xms_edov false' => [['xms_edov' => false]],
    'xms_edov as a string' => [['xms_edov' => 'true']],
]);

it('reads xms_edov only from an Entra sign-in', function (): void {
    $google = new FederatedPrincipal(provider: 'oidc', subject: 's', email: 'a@acme.example', raw: ['iss' => 'https://accounts.google.com', 'xms_edov' => true]);
    $entra = new FederatedPrincipal(provider: 'oidc', subject: 's', email: 'a@acme.example', raw: ['iss' => 'https://login.microsoftonline.com/'.RootEntra::TENANT.'/v2.0', 'xms_edov' => true]);
    $operator = new FederatedPrincipal(provider: 'social:microsoft', subject: 's', email: 'a@acme.example', raw: ['xms_edov' => true]);

    expect(EntraVerifiedEmail::for($google, null))->toBeFalse()
        ->and(EntraVerifiedEmail::for($entra, null))->toBeTrue()
        ->and(EntraVerifiedEmail::for($operator, null))->toBeTrue()
        ->and(VouchedEmail::for($operator, null))->toBeTrue();
});

it('lets an organization’s Entra connection vouch only inside a domain that organization proved', function (bool $proven): void {
    $connection = new Connection;
    $connection->forceFill(['organization_id' => 'org_contoso', 'provider' => 'microsoft']);

    $principal = new FederatedPrincipal(provider: 'oidc', subject: 's', email: 'dana@contoso.example', raw: ['xms_edov' => true]);

    // The framework's own rule, answered as the domain table would.
    app()->instance(OrganizationVouchedEmail::class, new class($proven) extends OrganizationVouchedEmail
    {
        public function __construct(private readonly bool $proven) {}

        public function verified(Connection $connection, ?string $email, mixed $providerSays): ?bool
        {
            return $providerSays === true && $this->proven ? true : null;
        }
    });

    expect(VouchedEmail::for($principal, $connection))->toBe($proven);
})->with(['domain proven' => true, 'domain not proven' => false]);

it('tells the administrator to add the xms_edov claim when setting Microsoft up', function (): void {
    $template = ProviderCatalog::find('microsoft');
    expect($template)->not->toBeNull();

    $steps = ProviderSetupSteps::for($template);

    expect(end($steps))->toContain('xms_edov')->toContain('Token configuration')
        ->and(ProviderSetupSteps::for(ProviderCatalog::find('google')))->not->toContain(end($steps));

    // And the console's Social sign-in page shows it.
    RootGoogle::operatorSignedIn();
    $page = test()->get('https://'.RootGoogle::HOST.'/admin/social-sign-in?provider=microsoft')->assertOk();

    expect((string) json_encode($page->inertiaProps()))->toContain('xms_edov');
});
