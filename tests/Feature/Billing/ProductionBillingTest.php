<?php

declare(strict_types=1);

use App\Platform\Entitlements;
use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Enums\TaxBasis;
use Cbox\Billing\Client\Enums\TaxStatus;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\ValueObjects\PaymentMethod;
use Cbox\Billing\Client\ValueObjects\Plan;
use Cbox\Billing\Client\ValueObjects\PriceTax;
use Cbox\Id\Billing\Contracts\ProjectPayments;
use Cbox\Id\Billing\HttpProjectPayments;
use Cbox\Id\Billing\Jobs\SyncBilling;
use Cbox\Id\Billing\Models\BillingPlan;
use Cbox\Id\Billing\Models\ProjectSubscription;
use Cbox\Id\Billing\PlanCatalog;
use Cbox\Id\Billing\Subscriptions;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function billingCatalogFake(string $currency = 'DKK'): FakeManagementTransport
{
    $fake = (new FakeManagementTransport)->withPlan(new Plan('id-start', 'Start', 10000, $currency, 'month', [], ['cbox-id.environments' => 3], 'cbox-id'));
    app()->instance(BillingManagement::class, new BillingManagement($fake));

    return $fake;
}

it('preserves catalog and grants when billing returns an empty catalog or unknown plan', function (): void {
    billingCatalogFake();
    expect(app(PlanCatalog::class)->sync())->toBe(1);
    app()->instance(BillingManagement::class, new BillingManagement(new FakeManagementTransport));
    expect(fn () => app(PlanCatalog::class)->sync())->toThrow(RuntimeException::class);
    expect(BillingPlan::query()->whereKey('id-start')->first()->sold)->toBeTrue();
    $project = provisionAccount()['project'];
    $before = $project->environment_limit;
    expect(fn () => app(Subscriptions::class)->apply($project, ['plan' => 'unknown', 'status' => 'active']))->toThrow(RuntimeException::class);
    expect($project->fresh()->environment_limit)->toBe($before);
});

it('applies only authoritative newer subscription allowances and keeps environments when a subscription ends', function (): void {
    config(['cbox-id.entitlements.mode' => 'inclusive', 'billing.require_subscription' => true]);
    billingCatalogFake();
    app(PlanCatalog::class)->sync();
    $project = provisionAccount()['project'];
    $subscriptions = app(Subscriptions::class);
    $subscriptions->apply($project, ['plan' => 'id-start', 'status' => 'active'], new DateTimeImmutable('2026-11-06T12:00:00Z'));
    expect($project->fresh()->environment_limit)->toBe(3);
    $subscriptions->apply($project, ['plan' => 'id-start', 'status' => 'canceled'], new DateTimeImmutable('2026-11-06T11:00:00Z'));
    expect($project->fresh()->environment_limit)->toBe(3);
    $subscriptions->apply($project, ['plan' => 'id-start', 'status' => 'past_due'], new DateTimeImmutable('2026-11-06T13:00:00Z'));
    expect($project->fresh()->environment_limit)->toBe(3);
    $subscriptions->apply($project, ['plan' => 'id-start', 'status' => 'ended'], new DateTimeImmutable('2026-11-06T14:00:00Z'));
    expect($project->fresh()->environment_limit)->toBe(0)
        ->and(ProjectSubscription::query()->find($project->id)->status)->toBe('ended');
    foreach (['sso', 'scim', 'audit_logs'] as $feature) {
        expect(app(Entitlements::class)->entitled($project->organization_id, $feature))->toBeTrue();
    }
});

it('accepts only current signed billing notifications', function (): void {
    Queue::fake();
    config(['billing.webhook_secret' => 'test-secret']);
    $body = '{"type":"subscription.updated"}';
    $time = time();
    $signature = hash_hmac('sha256', $time.'.'.$body, 'test-secret');
    $this->call('POST', '/api/billing/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CBOX_SIGNATURE' => "t={$time},v1={$signature}"], $body)->assertAccepted();
    Queue::assertPushed(SyncBilling::class);
    config(['billing.enabled' => false]);
    $this->call('POST', '/api/billing/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CBOX_SIGNATURE' => "t={$time},v1={$signature}"], $body)->assertNotFound();
    config(['billing.enabled' => true]);
    $this->call('POST', '/api/billing/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CBOX_SIGNATURE' => "t={$time},v1={$signature}"], '{}')->assertUnauthorized();
    $old = $time - 301;
    $signature = hash_hmac('sha256', $old.'.'.$body, 'test-secret');
    $this->call('POST', '/api/billing/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CBOX_SIGNATURE' => "t={$old},v1={$signature}"], $body)->assertUnauthorized();
});

it('refuses cross-workspace billing writes and read-only members before any payment call', function (): void {
    config(['billing-client.base_url' => 'https://payments.test', 'billing-client.api_token' => 'test']);
    $a = provisionAccount('owner-a@example.test');
    $b = provisionAccount('owner-b@example.test');
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($a['subjectId'], 'owner-a@example.test'));
    signInAsMember($a['subjectId']);
    $this->postJson(route('billing.preview', $b['project']->id), ['operation' => 'plan', 'plan' => 'id-start'])->assertNotFound();
    [, $viewer] = memberWithRole($a['organization']->id, MembershipRole::Viewer, 'viewer@example.test');
    signInAsMember($viewer);
    $this->postJson(route('billing.card', ['billingOrganization' => $a['organization']->id]), ['operation' => 'setup'])->assertForbidden();
});

it('previews and confirms a project plan only once across repeated confirmations', function (): void {
    config(['billing-client.base_url' => 'https://payments.test', 'billing-client.api_token' => 'test']);
    billingCatalogFake();
    app(PlanCatalog::class)->sync();
    $a = provisionAccount('pay-owner@example.test');
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($a['subjectId'], 'pay-owner@example.test'));
    signInAsMember($a['subjectId']);
    $preview = ['due_now_minor' => 10000, 'new_recurring_minor' => 10000, 'new_recurring_tax' => ['total_minor' => 12500], 'consequences' => []];
    $payments = Mockery::mock(ProjectPayments::class);
    $payments->shouldReceive('subscription')->times(3)->with($a['organization']->id, $a['project']->id)->andReturn(['plan' => 'id-start', 'status' => 'active', 'recurring' => ['currency' => 'DKK']]);
    $payments->shouldReceive('perform')->with('preview', $a['organization']->id, $a['project']->id, ['plan' => 'id-start'], Mockery::type('string'))->twice()->andReturn($preview);
    $payments->shouldReceive('perform')->with('change', $a['organization']->id, $a['project']->id, ['plan' => 'id-start'], Mockery::type('string'))->once()->andReturn($preview);
    app()->instance(ProjectPayments::class, $payments);
    $quote = $this->postJson(route('billing.preview', $a['project']->id), ['operation' => 'plan', 'plan' => 'id-start'])->assertOk()->json('quote');
    $this->postJson(route('billing.confirm', $a['project']->id), ['quote' => $quote])->assertOk();
    $this->postJson(route('billing.confirm', $a['project']->id), ['quote' => $quote])->assertOk();
    expect($a['project']->fresh()->environment_limit)->toBe(3);
});

it('requires a new confirmation if the charge or consequences changed after preview', function (): void {
    config(['billing-client.base_url' => 'https://payments.test', 'billing-client.api_token' => 'test']);
    billingCatalogFake();
    app(PlanCatalog::class)->sync();
    $a = provisionAccount('price-owner@example.test');
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($a['subjectId'], 'price-owner@example.test'));
    signInAsMember($a['subjectId']);
    $payments = Mockery::mock(ProjectPayments::class);
    $payments->shouldReceive('subscription')->twice()->andReturn(['plan' => 'id-start', 'status' => 'active', 'recurring' => ['currency' => 'DKK']]);
    $payments->shouldReceive('perform')->with('preview', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())->twice()->andReturn(['due_now_minor' => 100, 'new_recurring_tax' => ['total_minor' => 12500]], ['due_now_minor' => 200, 'new_recurring_tax' => ['total_minor' => 12500]]);
    app()->instance(ProjectPayments::class, $payments);
    $quote = $this->postJson(route('billing.preview', $a['project']->id), ['operation' => 'plan', 'plan' => 'id-start'])->assertOk()->json('quote');
    $this->postJson(route('billing.confirm', $a['project']->id), ['quote' => $quote])->assertConflict();
});

it('does not apply an old billing form to the workspace selected in another tab', function (): void {
    config(['billing-client.base_url' => 'https://payments.test', 'billing-client.api_token' => 'test']);
    $a = provisionAccount('tab-billing-a@example.test');
    $b = provisionAccount('tab-billing-b@example.test');
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($b['subjectId'], 'tab-billing-b@example.test'));
    signInAsMember($b['subjectId']);
    $this->postJson(route('billing.card', ['billingOrganization' => $a['organization']->id]), ['operation' => 'setup'])->assertConflict();
    $this->getJson(route('billing.account', ['billingOrganization' => $a['organization']->id]))->assertConflict();
});

it('sends project references and stable idempotency keys through the payment adapter', function (): void {
    config(['billing-client.base_url' => 'https://payments.test', 'billing-client.api_token' => 'test']);
    Http::fake(['payments.test/*' => Http::response(['subscription' => ['plan' => 'id-start', 'status' => 'active']])]);
    $adapter = new HttpProjectPayments;
    $adapter->subscription('workspace-a', 'project-a');
    $adapter->perform('subscribe', 'workspace-a', 'project-a', ['plan' => 'id-start'], 'confirmation-a');
    Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), 'reference=project-a'));
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['org'] === 'workspace-a' && $request['reference'] === 'project-a' && $request->hasHeader('Idempotency-Key', 'confirmation-a'));
});

it('does not interpret a reverse-proxy 404 as an absent subscription', function (): void {
    config(['billing-client.base_url' => 'https://payments.test', 'billing-client.api_token' => 'test']);
    Http::fake(['payments.test/*' => Http::response('<html>Not found</html>', 404)]);
    expect(fn () => (new HttpProjectPayments)->subscription('workspace-a', 'project-a'))->toThrow(TransportException::class);
});

it('uses the same scoped billing actions from the console and management API', function (): void {
    config(['billing-client.base_url' => 'https://payments.test', 'billing-client.api_token' => 'test']);
    $fake = billingCatalogFake();
    $a = provisionAccount('details-owner@example.test');
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($a['subjectId'], 'details-owner@example.test'));
    signInAsMember($a['subjectId']);
    $input = ['invoice_name' => 'Acme ApS', 'billing_email' => 'invoice@example.test', 'address_line_1' => 'Testvej 1', 'city' => 'Aarhus', 'postal_code' => '8000', 'country' => 'DK', 'tax_id' => '12 34 56 78'];
    $this->postJson(route('billing.details', ['billingOrganization' => $a['organization']->id]), $input)
        ->assertOk()->assertJsonPath('profile.taxId', 'DK12345678');
    $admin = app(OrganizationApiKeys::class)->issue($a['organization']->id, 'Billing agent', MembershipRole::Admin, null, ['billing:write'])->plaintext;
    $this->withToken($admin)->putJson('/api/v1/workspace/billing/details', $input + ['subdivision' => ''])
        ->assertOk()->assertJsonPath('data.billingEmail', 'invoice@example.test');
    $restricted = app(OrganizationApiKeys::class)->issue($a['organization']->id, 'Read agent', MembershipRole::Admin, null, ['workspace:read'])->plaintext;
    $this->withToken($restricted)->putJson('/api/v1/workspace/billing/details', $input)->assertForbidden();
    $fake->withPaymentMethod($a['organization']->id, new PaymentMethod('card-own', 'visa', '4242', 12, 2030, true));
    $this->withToken($admin)->postJson('/api/v1/workspace/billing/payment-methods', ['operation' => 'remove', 'card' => 'card-own'])->assertUnprocessable();
    $this->withToken($admin)->postJson('/api/v1/workspace/billing/payment-methods', ['operation' => 'default', 'card' => 'card-elsewhere'])->assertNotFound();
    $limit = $a['project']->fresh()->environment_limit;
    config(['billing-client.api_token' => null]);
    $this->withToken($admin)->putJson('/api/v1/workspace/billing/details', $input)->assertStatus(503);
    expect($a['project']->fresh()->environment_limit)->toBe($limit);
});

it('refuses a renewal confirmation after the subscription becomes paused', function (): void {
    config(['billing-client.base_url' => 'https://payments.test', 'billing-client.api_token' => 'test']);
    $a = provisionAccount('renewal-owner@example.test');
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($a['subjectId'], 'renewal-owner@example.test'));
    signInAsMember($a['subjectId']);
    $subscription = ['status' => 'active', 'cancel_at_period_end' => true, 'paused' => false, 'interval' => 'month', 'period_end' => '2026-12-06T00:00:00Z', 'recurring' => ['net_minor' => 10000, 'currency' => 'DKK', 'tax' => ['total_minor' => 12500]]];
    $payments = Mockery::mock(ProjectPayments::class);
    $payments->shouldReceive('subscription')->twice()->andReturn($subscription, array_replace($subscription, ['paused' => true]));
    app()->instance(ProjectPayments::class, $payments);
    $quote = $this->postJson(route('billing.preview', $a['project']->id), ['operation' => 'reactivate'])
        ->assertOk()->assertJsonPath('preview.new_recurring_tax.total_minor', 12500)->json('quote');
    $this->postJson(route('billing.confirm', $a['project']->id), ['quote' => $quote])->assertConflict();
});

it('quotes tax-inclusive first checkout and binds its currency and project before redirecting', function (string $currency): void {
    config(['billing-client.base_url' => 'https://payments.test', 'billing-client.api_token' => 'test', 'billing.currency' => $currency]);
    $fake = billingCatalogFake($currency)->withTax(new PriceTax(TaxStatus::Calculated, TaxBasis::Customer, 'DK', rate: '25'));
    app(PlanCatalog::class)->sync();
    config(['billing.currency' => 'DKK']); // A shared account can have a different locked currency.
    $a = provisionAccount('first-checkout@example.test');
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($a['subjectId'], 'first-checkout@example.test'));
    signInAsMember($a['subjectId']);
    $payments = Mockery::mock(ProjectPayments::class);
    $payments->shouldReceive('subscription')->twice()->andReturn(null);
    $payments->shouldReceive('perform')->once()->with('checkout', $a['organization']->id, $a['project']->id,
        Mockery::on(fn ($input) => $input['plan'] === 'id-start' && $input['currency'] === $currency && $input['return_url'] === route('billing')), Mockery::type('string'))
        ->andReturn(['url' => 'https://checkout.example.test/session']);
    app()->instance(ProjectPayments::class, $payments);
    $quote = $this->postJson(route('billing.preview', $a['project']->id), ['operation' => 'plan', 'plan' => 'id-start'])
        ->assertOk()->assertJsonPath('operation', 'checkout')->assertJsonPath('preview.due_now_minor', 12500)->json('quote');
    $this->postJson(route('billing.confirm', $a['project']->id), ['quote' => $quote])->assertOk()->assertJsonPath('url', 'https://checkout.example.test/session');
})->with(['DKK', 'EUR']);
