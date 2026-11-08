<?php

declare(strict_types=1);

use App\Platform\Actions\ActionRefused;
use App\Platform\Billing\CaptureProductUsage;
use App\Platform\Billing\ProductBilling;
use Cbox\Billing\Client\BillingClient;
use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Contracts\ManagementTransport;
use Cbox\Billing\Client\Enums\FeatureSource;
use Cbox\Billing\Client\Enums\FeatureType;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\ValueObjects\FeatureEntitlement;
use Cbox\Billing\Client\ValueObjects\FeatureEntitlements;
use Cbox\Id\Kernel\Events\EventDelivered;
use Cbox\Id\Kernel\Events\Models\Event;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

it('shares a read snapshot only within its exact HTTP request and workspace', function (): void {
    ['organization' => $workspace] = provisionAccount();
    $transport = centralBillingFixture();
    $transport->feature($workspace->id, new FeatureEntitlement('cbox-id.sso', FeatureType::Boolean, true));
    Route::get('/__billing-read/{organization}', function (string $organization) {
        $billing = app(ProductBilling::class);

        return response()->json(['first' => $billing->enabled($organization, 'sso'), 'second' => $billing->enabled($organization, 'sso')]);
    });
    $this->getJson('/__billing-read/'.$workspace->id)->assertOk()->assertJson(['first' => true, 'second' => true]);
    $this->getJson('/__billing-read/unrelated-workspace')->assertOk()->assertJson(['first' => false, 'second' => false]);
    $transport->feature($workspace->id, new FeatureEntitlement('cbox-id.sso', FeatureType::Boolean, false));
    $this->getJson('/__billing-read/'.$workspace->id)->assertOk()->assertJson(['first' => false, 'second' => false]);
});

it('uses only current central grants for the exact workspace and honors immediate revocation', function (): void {
    ['organization' => $workspace] = provisionAccount();
    $billing = app(ProductBilling::class);
    expect($billing->enabled($workspace->id, 'sso'))->toBeFalse();
    centralBillingFixture()->feature($workspace->id, new FeatureEntitlement('cbox-id.sso', FeatureType::Boolean, true, source: FeatureSource::Override));
    expect($billing->enabled($workspace->id, 'sso'))->toBeTrue();
    centralBillingFixture()->feature($workspace->id, new FeatureEntitlement('cbox-id.sso', FeatureType::Boolean, false, source: FeatureSource::Override));
    expect($billing->enabled($workspace->id, 'sso'))->toBeFalse();
    centralBillingFixture()->suspendAccount($workspace->id);
    expect($billing->environmentLimit($workspace->id))->toBe(0);
});

it('maps a directory organization to its project owning workspace without crossing accounts', function (): void {
    ['organization' => $workspace, 'environment' => $environment] = provisionAccount();
    app(EnvironmentContext::class)->set(GenericEnvironment::of($environment->id));
    $customer = app(Organizations::class)->create(new NewOrganization('Customer', 'product-customer'));
    centralBillingFixture()->feature($customer->id, new FeatureEntitlement('cbox-id.scim', FeatureType::Boolean, true));
    $billing = app(ProductBilling::class);
    expect($billing->owningWorkspace($customer->id))->toBe($workspace->id)
        ->and($billing->enabled($customer->id, 'scim'))->toBeFalse();
    centralBillingFixture()->feature($workspace->id, new FeatureEntitlement('cbox-id.scim', FeatureType::Boolean, true));
    expect($billing->enabled($customer->id, 'scim'))->toBeTrue();
});

it('fails closed for an outage and a mismatched feature account', function (): void {
    $client = Mockery::mock(BillingClient::class);
    $client->shouldReceive('features')->with('exact-workspace')->once()->andReturn(new FeatureEntitlements('other-workspace'));
    app()->instance(BillingClient::class, $client);
    expect(fn () => app(ProductBilling::class)->environmentLimit('exact-workspace'))->toThrow(ActionRefused::class);
    $client = Mockery::mock(BillingClient::class);
    $client->shouldReceive('features')->with('exact-workspace')->once()->andThrow(new RuntimeException('offline'));
    app()->instance(BillingClient::class, $client);
    expect(fn () => app(ProductBilling::class)->environmentLimit('exact-workspace'))->toThrow(ActionRefused::class);
});

it('caps local project allowances and denies zero absent and malformed grants', function (): void {
    ['organization' => $workspace, 'project' => $project] = provisionAccount();
    $billing = app(ProductBilling::class);
    grantProductEnvironmentAllowance($workspace->id, 1);
    expect($billing->projectLimit($project))->toBe(1);
    expect(fn () => $billing->assertEnvironmentCapacity($workspace->id, $project))->toThrow(ActionRefused::class);
    grantProductEnvironmentAllowance($workspace->id, 100);
    expect($billing->projectLimit($project))->toBe($project->environment_limit);
    centralBillingFixture()->feature($workspace->id, new FeatureEntitlement('cbox-id.environments', FeatureType::Config, true, 'unlimited'));
    expect($billing->environmentLimit($workspace->id))->toBe(0);
    expect(fn () => $billing->assertEnvironmentCapacity('foreign', $project))->toThrow(ActionRefused::class);
});

it('reports durable usage once to the exact project owner and keeps rejected events pending', function (): void {
    ['organization' => $workspace, 'environment' => $environment] = provisionAccount();
    $event = new Event([
        'id' => 'event-usage-test', 'type' => 'user.login', 'environment_id' => $environment->id,
        'organization_id' => 'a-directory-customer', 'occurred_at' => now(), 'payload' => [],
    ]);
    $capture = app(CaptureProductUsage::class);
    $capture->handle(new EventDelivered($event));
    $capture->handle(new EventDelivered($event));
    expect(DB::table('product_billing_usage_outbox')->count())->toBe(1);
    $transport = (new FakeManagementTransport)->withUsageEventMeter('cbox-id.sso_sign_ins', 'temporarily_refused');
    app()->instance(ManagementTransport::class, $transport);
    app()->forgetInstance(BillingManagement::class);
    $this->artisan('billing:report-product-usage')->assertFailed();
    expect(DB::table('product_billing_usage_outbox')->value('reported_at'))->toBeNull();
    $transport->withUsageEventMeter('cbox-id.sso_sign_ins');
    $this->artisan('billing:report-product-usage')->assertSuccessful();
    $this->artisan('billing:report-product-usage')->assertSuccessful();
    expect($transport->recordedUsageEvents($workspace->id))->toHaveCount(1)
        ->and($transport->recordedUsageEvents('a-directory-customer'))->toBe([]);
});

it('does not meter root identity infrastructure or unknown events to customers', function (): void {
    $root = platformRootEnvironment();
    $event = new Event([
        'id' => 'event-root-test', 'type' => 'user.login', 'environment_id' => $root->id,
        'organization_id' => 'customer', 'occurred_at' => now(), 'payload' => [],
    ]);
    app(CaptureProductUsage::class)->handle(new EventDelivered($event));
    expect(DB::table('product_billing_usage_outbox')->count())->toBe(0);
});

it('rechecks the central ceiling inside project and environment creation actions', function (): void {
    ['organization' => $workspace, 'project' => $project] = provisionAccount();
    $key = app(OrganizationApiKeys::class)->issue($workspace->id, 'Provisioner', MembershipRole::Admin, null, ['projects:write', 'environments:write'])->plaintext;
    grantProductEnvironmentAllowance($workspace->id, 1);
    $this->withToken($key)->postJson('/api/v1/workspace/projects', ['name' => 'Too large', 'environment_limit' => 2])
        ->assertUnprocessable()->assertJsonPath('error', 'environment_limit_reached');
    $this->withToken($key)->postJson('/api/v1/workspace/environments', ['name' => 'Over quota', 'project_id' => $project->id])
        ->assertUnprocessable()->assertJsonPath('error', 'environment_limit_reached');
    expect(Project::query()->where('name', 'Too large')->exists())->toBeFalse();
    grantProductEnvironmentAllowance($workspace->id, 2);
    $this->withToken($key)->postJson('/api/v1/workspace/environments', ['name' => 'Allowed', 'project_id' => $project->id])->assertCreated();
    grantProductEnvironmentAllowance($workspace->id, 0);
    $this->withToken($key)->postJson('/api/v1/workspace/projects', ['name' => 'Revoked'])->assertUnprocessable();
    expect(Project::query()->where('name', 'Revoked')->exists())->toBeFalse();
});
