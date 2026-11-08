<?php

use App\SignalsData\Bundled\IdManagedSources;
use App\SignalsData\Models\SourceHead;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Enums\ProjectStatus;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Cbox\Id\Platform\Models\Project;
use Cbox\Signals\Contracts\ManagedCredential;
use Cbox\Signals\Contracts\ManagedIntegration;
use Cbox\Signals\Contracts\ManagedSources;
use Cbox\Signals\Data\EnrollmentStatus;
use Cbox\Signals\Testing\FakeManagedIntegration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    platformRootEnvironment();
});

/** Environment API keys are environment-owned; read them from inside that environment like the product does. */
function keysOf(Environment $environment): Collection
{
    return app(EnvironmentContext::class)->runAs(GenericEnvironment::of($environment->id), fn () => EnvironmentApiKey::query()->where('name', IdManagedSources::KEY_NAME)->orderBy('id')->get());
}

function capturedEnvironment(string $organization = 'acme'): Environment
{
    $account = Organization::query()->create(['name' => 'Acme', 'slug' => $organization, 'status' => OrganizationStatus::Active]);
    $project = Project::query()->create(['organization_id' => $account->id, 'name' => 'Acme product', 'slug' => $organization.'-product', 'status' => ProjectStatus::Active, 'environment_limit' => 2]);
    $environment = Environment::query()->create(['project_id' => $project->id, 'name' => 'Production', 'slug' => $organization.'-'.Str::lower((string) Str::ulid()), 'type' => EnvironmentType::Production, 'status' => EnvironmentStatus::Active, 'settings' => []]);
    config(['signals-data.environments' => [$environment->id]]);

    return $environment;
}

it('offers the captured environments owned through their project and skips unowned or suspended ones', function (): void {
    $environment = capturedEnvironment();
    $orphan = Environment::query()->create(['name' => 'Orphan', 'slug' => 'orphan-'.Str::lower((string) Str::ulid()), 'type' => EnvironmentType::Production, 'status' => EnvironmentStatus::Active, 'settings' => []]);
    config(['signals-data.environments' => [$environment->id, $orphan->id]]);
    $sources = app(ManagedSources::class);

    $offered = iterator_to_array($sources->environments(), false);
    expect($sources->provider())->toBe('cbox_id')->and($offered)->toHaveCount(1)
        ->and($offered[0]->ownerId)->toBe($environment->id)
        ->and($offered[0]->organization)->toBe(Project::query()->firstOrFail()->organization_id)
        ->and($offered[0]->url)->toStartWith('http')->and(parse_url($offered[0]->url, PHP_URL_PATH))->toBeNull();

    $environment->forceFill(['status' => EnvironmentStatus::Suspended])->save();
    SourceHead::query()->create(['environment_id' => $environment->id, 'generation' => 1, 'sequence' => 0, 'retained_after' => 0, 'record_count' => 0, 'started_at' => now()]);
    expect(iterator_to_array($sources->environments(), false))->toBe([])
        ->and(iterator_to_array($sources->retired(), false))->toBe([$environment->id]);
});

it('issues export-only environment keys and retires the rest only after acceptance', function (): void {
    $environment = capturedEnvironment();
    $sources = app(ManagedSources::class);
    $offered = iterator_to_array($sources->environments(), false)[0];

    $first = $sources->issueSecret($offered);
    $second = $sources->issueSecret($offered);
    $keys = keysOf($environment);
    expect($keys)->toHaveCount(2)->and($keys[0]->scopes)->toBe(['signals_source:read'])->and($first->reference)->not->toBe($second->reference)
        ->and($first->plaintext)->not->toBe($second->plaintext);

    $sources->retireSecrets($offered, $second->reference);
    expect(keysOf($environment)->firstWhere('id', $first->reference)->revoked_at)->not->toBeNull()
        ->and(keysOf($environment)->firstWhere('id', $second->reference)->revoked_at)->toBeNull();

    $sources->discardSecret($offered, $second->reference);
    expect(keysOf($environment)->firstWhere('id', $second->reference)->revoked_at)->not->toBeNull();
});

it('authenticates as a confidential client over the public token endpoint and caches the token', function (): void {
    config(['signals.bundled.client_id' => 'cid_signals', 'signals.bundled.client_secret' => 'top-secret', 'signals.bundled.token_url' => 'https://cbox.cboxid.test/oauth/token', 'signals.bundled.resource' => 'https://app.cboxsignals.test']);
    Http::fake(['https://cbox.cboxid.test/oauth/token' => Http::response(['access_token' => 'machine-token', 'token_type' => 'Bearer', 'expires_in' => 600])]);

    $credential = app(ManagedCredential::class);
    expect($credential->token())->toBe('machine-token')->and(app(ManagedCredential::class)->token())->toBe('machine-token');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['grant_type'] === 'client_credentials' && $request['client_id'] === 'cid_signals' && $request['resource'] === 'https://app.cboxsignals.test' && $request->url() === 'https://cbox.cboxid.test/oauth/token');

    config(['signals.bundled.client_id' => '']);
    expect(app(ManagedCredential::class)->token())->toBe('');
});

it('reconciles through the command: enrol once, no writes when unchanged, revoke what is gone', function (): void {
    $environment = capturedEnvironment();
    $signals = new FakeManagedIntegration;
    app()->instance(ManagedIntegration::class, $signals);
    app()->instance(ManagedCredential::class, new class implements ManagedCredential
    {
        public function token(): string
        {
            return 'machine-token';
        }
    });

    expect(Artisan::call('signals:managed-reconcile'))->toBe(0, Artisan::output());
    expect($signals->enrollCalls)->toHaveCount(1)->and($signals->enrollCalls[0]['owner_id'])->toBe($environment->id)
        ->and($signals->enrollments['cbox_id/'.$environment->id]->status)->toBe(EnrollmentStatus::Enrolled)
        ->and(keysOf($environment)->whereNull('revoked_at'))->toHaveCount(1);

    $this->artisan('signals:managed-reconcile')->assertExitCode(0);
    expect($signals->enrollCalls)->toHaveCount(1);

    SourceHead::query()->create(['environment_id' => $environment->id, 'generation' => 1, 'sequence' => 0, 'retained_after' => 0, 'record_count' => 0, 'started_at' => now()]);
    $environment->forceFill(['status' => EnvironmentStatus::Suspended])->save();
    $this->artisan('signals:managed-reconcile')->assertExitCode(0);
    expect($signals->revokeCalls)->toBe([['provider' => 'cbox_id', 'owner_id' => $environment->id]])
        ->and($signals->enrollments['cbox_id/'.$environment->id]->status)->toBe(EnrollmentStatus::Revoked);
});
