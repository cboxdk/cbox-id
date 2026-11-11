<?php

declare(strict_types=1);

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Enums\TaxBasis;
use Cbox\Billing\Client\Enums\TaxStatus;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\ValueObjects\Plan;
use Cbox\Billing\Client\ValueObjects\PriceTax;
use Cbox\Id\Billing\Contracts\ProjectPayments;
use Cbox\Id\Billing\HttpProjectPayments;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Http;

function billingCatalogFake(string $currency = 'DKK'): FakeManagementTransport
{
    $fake = (new FakeManagementTransport)->withPlan(new Plan('id-start', 'Start', 10000, $currency, 'month', [], ['cbox-id.environments' => 3], 'cbox-id'));
    app()->instance(BillingManagement::class, new BillingManagement($fake));

    return $fake;
}

it('refuses cross-workspace billing writes and read-only members before any payment call', function (): void {
    config(['billing-client.base_url' => 'https://payments.test', 'billing-client.api_token' => 'test']);
    $a = provisionAccount('owner-a@example.test');
    $b = provisionAccount('owner-b@example.test');
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($a['subjectId'], 'owner-a@example.test'));
    signInAsMember($a['subjectId']);
    $this->postJson(route('orgs.settings.billing.preview', $b['project']->id), ['operation' => 'plan', 'plan' => 'id-start'])->assertNotFound();
    [, $viewer] = memberWithRole($a['organization']->id, MembershipRole::Viewer, 'viewer@example.test');
    signInAsMember($viewer);
    $this->postJson(route('orgs.settings.billing.setup'), ['organization' => $a['organization']->id])->assertForbidden();
});

it('previews and confirms a project plan only once across repeated confirmations', function (): void {
    config(['billing-client.base_url' => 'https://payments.test', 'billing-client.api_token' => 'test']);
    billingCatalogFake();
    $a = provisionAccount('pay-owner@example.test');
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($a['subjectId'], 'pay-owner@example.test'));
    signInAsMember($a['subjectId']);
    $preview = ['due_now_minor' => 10000, 'new_recurring_minor' => 10000, 'new_recurring_tax' => ['total_minor' => 12500], 'consequences' => []];
    $payments = Mockery::mock(ProjectPayments::class);
    $payments->shouldReceive('subscription')->twice()->with($a['organization']->id, $a['project']->id)->andReturn(['plan' => 'id-start', 'status' => 'active', 'recurring' => ['currency' => 'DKK']]);
    $payments->shouldReceive('perform')->with('preview', $a['organization']->id, $a['project']->id, ['plan' => 'id-start'], Mockery::type('string'))->twice()->andReturn($preview);
    $payments->shouldReceive('perform')->with('change', $a['organization']->id, $a['project']->id, ['plan' => 'id-start'], Mockery::type('string'))->once()->andReturn($preview);
    app()->instance(ProjectPayments::class, $payments);
    $quote = $this->postJson(route('orgs.settings.billing.preview', $a['project']->id), ['operation' => 'plan', 'plan' => 'id-start'])->assertOk()->json('quote');
    $this->postJson(route('orgs.settings.billing.confirm', $a['project']->id), ['quote' => $quote])->assertOk();
    $this->postJson(route('orgs.settings.billing.confirm', $a['project']->id), ['quote' => $quote])->assertOk();
    expect($a['project']->fresh()->environment_limit)->toBe($a['project']->environment_limit);
});

it('requires a new confirmation if the charge or consequences changed after preview', function (): void {
    config(['billing-client.base_url' => 'https://payments.test', 'billing-client.api_token' => 'test']);
    billingCatalogFake();
    $a = provisionAccount('price-owner@example.test');
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($a['subjectId'], 'price-owner@example.test'));
    signInAsMember($a['subjectId']);
    $payments = Mockery::mock(ProjectPayments::class);
    $payments->shouldReceive('subscription')->twice()->andReturn(['plan' => 'id-start', 'status' => 'active', 'recurring' => ['currency' => 'DKK']]);
    $payments->shouldReceive('perform')->with('preview', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())->twice()->andReturn(['due_now_minor' => 100, 'new_recurring_tax' => ['total_minor' => 12500]], ['due_now_minor' => 200, 'new_recurring_tax' => ['total_minor' => 12500]]);
    app()->instance(ProjectPayments::class, $payments);
    $quote = $this->postJson(route('orgs.settings.billing.preview', $a['project']->id), ['operation' => 'plan', 'plan' => 'id-start'])->assertOk()->json('quote');
    $this->postJson(route('orgs.settings.billing.confirm', $a['project']->id), ['quote' => $quote])->assertConflict();
});

it('does not apply an old billing form to the organization selected in another tab', function (): void {
    config(['billing-client.base_url' => 'https://payments.test', 'billing-client.api_token' => 'test']);
    $a = provisionAccount('tab-billing-a@example.test');
    $b = provisionAccount('tab-billing-b@example.test');
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($b['subjectId'], 'tab-billing-b@example.test'));
    signInAsMember($b['subjectId']);
    $this->postJson(route('orgs.settings.billing.setup'), ['organization' => $a['organization']->id])->assertForbidden();
    $this->getJson(route('orgs.settings.billing', ['organization' => $a['organization']->id]))->assertForbidden();
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

it('refuses a renewal confirmation after the subscription becomes paused', function (): void {
    config(['billing-client.base_url' => 'https://payments.test', 'billing-client.api_token' => 'test']);
    $a = provisionAccount('renewal-owner@example.test');
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($a['subjectId'], 'renewal-owner@example.test'));
    signInAsMember($a['subjectId']);
    $subscription = ['status' => 'active', 'cancel_at_period_end' => true, 'paused' => false, 'interval' => 'month', 'period_end' => '2026-12-06T00:00:00Z', 'recurring' => ['net_minor' => 10000, 'currency' => 'DKK', 'tax' => ['total_minor' => 12500]]];
    $payments = Mockery::mock(ProjectPayments::class);
    $payments->shouldReceive('subscription')->twice()->andReturn($subscription, array_replace($subscription, ['paused' => true]));
    app()->instance(ProjectPayments::class, $payments);
    $quote = $this->postJson(route('orgs.settings.billing.preview', $a['project']->id), ['operation' => 'reactivate'])
        ->assertOk()->assertJsonPath('preview.new_recurring_tax.total_minor', 12500)->json('quote');
    $this->postJson(route('orgs.settings.billing.confirm', $a['project']->id), ['quote' => $quote])->assertConflict();
});

it('quotes tax-inclusive first checkout and binds its currency and project before redirecting', function (string $currency): void {
    config(['billing-client.base_url' => 'https://payments.test', 'billing-client.api_token' => 'test', 'billing.currency' => $currency]);
    $fake = billingCatalogFake($currency)->withTax(new PriceTax(TaxStatus::Calculated, TaxBasis::Customer, 'DK', rate: '25'));
    config(['billing.currency' => 'DKK']); // A shared account can have a different locked currency.
    $a = provisionAccount('first-checkout@example.test');
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($a['subjectId'], 'first-checkout@example.test'));
    signInAsMember($a['subjectId']);
    $payments = Mockery::mock(ProjectPayments::class);
    $payments->shouldReceive('subscription')->twice()->andReturn(null);
    $payments->shouldReceive('perform')->once()->with('checkout', $a['organization']->id, $a['project']->id,
        Mockery::on(fn ($input) => $input['plan'] === 'id-start' && $input['currency'] === $currency && $input['return_url'] === route('orgs.settings.billing', ['organization' => $a['organization']->id])), Mockery::type('string'))
        ->andReturn(['url' => 'https://checkout.example.test/session']);
    app()->instance(ProjectPayments::class, $payments);
    $quote = $this->postJson(route('orgs.settings.billing.preview', $a['project']->id), ['operation' => 'plan', 'plan' => 'id-start'])
        ->assertOk()->assertJsonPath('operation', 'checkout')->assertJsonPath('preview.due_now_minor', 12500)->json('quote');
    $this->postJson(route('orgs.settings.billing.confirm', $a['project']->id), ['quote' => $quote])->assertOk()->assertJsonPath('url', 'https://checkout.example.test/session');
})->with(['DKK', 'EUR']);

it('rechecks app billing authority before using a previously quoted confirmation', function (): void {
    config(['billing-client.base_url' => 'https://payments.test', 'billing-client.api_token' => 'test']);
    billingCatalogFake();
    $account = provisionAccount();
    [$member, $subject] = memberWithRole($account['organization']->id, MembershipRole::Viewer, 'revoked-billing@example.test');
    grantNativeProductRoles($member, ['billing']);
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($subject, 'revoked-billing@example.test'));
    signInAsMember($subject);
    $payments = Mockery::mock(ProjectPayments::class);
    $payments->shouldReceive('subscription')->once()->andReturn(['plan' => 'id-start', 'status' => 'active', 'recurring' => ['currency' => 'DKK']]);
    $payments->shouldReceive('perform')->once()->with('preview', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
        ->andReturn(['due_now_minor' => 100, 'new_recurring_tax' => ['total_minor' => 12500]]);
    app()->instance(ProjectPayments::class, $payments);
    $quote = $this->postJson(route('orgs.settings.billing.preview', $account['project']->id), ['operation' => 'plan', 'plan' => 'id-start'])->assertOk()->json('quote');
    grantNativeProductRoles($member, ['viewer']);
    $this->postJson(route('orgs.settings.billing.confirm', $account['project']->id), ['quote' => $quote])->assertForbidden();
});

it('refuses a first-checkout quote after another tab has already subscribed', function (): void {
    config(['billing-client.base_url' => 'https://payments.test', 'billing-client.api_token' => 'test']);
    billingCatalogFake()->withTax(new PriceTax(TaxStatus::Calculated, TaxBasis::Customer, 'DK', rate: '25'));
    $account = provisionAccount('checkout-race@example.test');
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($account['subjectId'], 'checkout-race@example.test'));
    signInAsMember($account['subjectId']);
    $payments = Mockery::mock(ProjectPayments::class);
    $payments->shouldReceive('subscription')->twice()->andReturn(null, ['plan' => 'id-start', 'status' => 'active']);
    $payments->shouldNotReceive('perform');
    app()->instance(ProjectPayments::class, $payments);
    $quote = $this->postJson(route('orgs.settings.billing.preview', $account['project']->id), ['operation' => 'plan', 'plan' => 'id-start'])->assertOk()->json('quote');
    $this->postJson(route('orgs.settings.billing.confirm', $account['project']->id), ['quote' => $quote])->assertConflict();
});
