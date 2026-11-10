<?php

declare(strict_types=1);

use Cbox\Billing\Client\Contracts\ManagementTransport;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\ValueObjects\Invoice;
use Cbox\Billing\Client\ValueObjects\PaymentMethod;
use Cbox\Billing\Client\ValueObjects\Subscription;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Inertia\Testing\AssertableInertia as Page;

beforeEach(function (): void {
    $this->payments = new class extends FakeManagementTransport
    {
        public array $refusedRemovals = [];

        public function removePaymentMethod(string $org, string $id): void
        {
            if (in_array($id, $this->refusedRemovals, true)) {
                throw TransportException::status('payment-methods', 422, '{"error":"payment_method_required"}');
            }
            parent::removePaymentMethod($org, $id);
        }
    };
    app()->instance(ManagementTransport::class, $this->payments);
});

it('shows the central account without exposing operational project records', function (): void {
    ['subjectId' => $owner, 'organization' => $organization] = provisionAccount();
    signInAsMember($owner);
    $this->payments->withInvoice($organization->id, new Invoice('INV-1', new DateTimeImmutable('2026-11-01'), 12500, 'DKK', 'paid', 10000, 2500));
    $this->get(route('billing', ['organization' => $organization->id]))->assertOk()->assertInertia(fn (Page $page) => $page
        ->where('organization', $organization->id)->where('environmentAllowance', 100)
        ->where('invoices.0.amountMinor', 12500)->where('invoices.0.date', '2026-11-01')
        ->missing('projects')->missing('usage')->has('countries'));
});

it('binds page account writes and card setup to the explicitly displayed organization', function (): void {
    ['subjectId' => $owner, 'organization' => $organization] = provisionAccount();
    signInAsMember($owner);
    $this->get('/billing')->assertRedirect(route('billing', ['organization' => $organization->id]));
    $this->get('/billing?organization=foreign')->assertForbidden();
    $this->postJson('/billing/payment-methods/setup', ['organization' => 'foreign'])->assertForbidden();
    $this->patch('/billing/account', ['organization' => 'foreign'])->assertForbidden();
    $this->get('/billing/invoices/INV-1/download?organization=foreign')->assertForbidden();
});

it('confirms the first centrally stored card and never changes another organization', function (): void {
    ['subjectId' => $owner, 'organization' => $organization] = provisionAccount();
    signInAsMember($owner);
    $this->postJson('/billing/payment-methods/setup', ['organization' => $organization->id])->assertOk()->assertJsonStructure(['clientSecret', 'publishableKey']);
    $this->postJson('/billing/payment-methods/complete', ['organization' => $organization->id])->assertStatus(409);
    $this->payments->withPaymentMethod($organization->id, new PaymentMethod('pm_1', 'visa', '4242', 12, 2030, false));
    $this->postJson('/billing/payment-methods/complete', ['organization' => 'foreign'])->assertForbidden();
    $this->postJson('/billing/payment-methods/complete', ['organization' => $organization->id, 'payment_method' => 'pm_1'])
        ->assertOk()->assertJsonPath('paymentMethods.0.isDefault', true);
    $this->postJson('/billing/payment-methods/complete', ['organization' => $organization->id])->assertForbidden();
});

it('scrubs payment provider secrets before rendering and uses a fresh document for CSP', function (): void {
    ['subjectId' => $owner, 'organization' => $organization] = provisionAccount();
    signInAsMember($owner);
    $this->get('/billing?organization='.$organization->id.'&setup_intent_client_secret=secret&setup_intent=seti_1&redirect_status=succeeded')
        ->assertRedirect(route('billing', ['organization' => $organization->id, 'setup_return' => 1]))->assertDontSee('secret');
    $this->withHeader('X-Inertia', 'true')->get(route('billing', ['organization' => $organization->id]))->assertStatus(409)->assertHeader('X-Inertia-Location', route('billing', ['organization' => $organization->id]));
});

it('gives billing members financial access and keeps readers out', function (): void {
    ['organization' => $organization] = provisionAccount();
    [$member, $subject] = memberWithRole($organization->id, MembershipRole::Viewer, 'financial-reader@example.test');
    grantNativeProductRoles($member, ['viewer']);
    signInAsMember($subject);
    $this->get(route('billing', ['organization' => $organization->id]))->assertForbidden();
    $this->postJson('/billing/payment-methods/setup', ['organization' => $organization->id])->assertForbidden();
    grantNativeProductRoles($member, ['billing']);
    $this->get(route('billing', ['organization' => $organization->id]))->assertOk()->assertInertia(fn (Page $page) => $page->where('canManage', true)->missing('projects'));
});

it('treats foreign and missing external payment method IDs identically on both organization actions', function (): void {
    ['organization' => $organization] = provisionAccount();
    $key = app(OrganizationApiKeys::class)->issue($organization->id, 'Billing worker', MembershipRole::Admin, null, ['billing:write'])->plaintext;
    $this->payments->withPaymentMethod('foreign-workspace', new PaymentMethod('pm_foreign', 'visa', '0000', 12, 2030, false));
    foreach ([['PATCH', '/default'], ['DELETE', '']] as [$verb, $suffix]) {
        $foreign = $this->withToken($key)->json($verb, '/api/v1/workspace/billing/payment-methods/pm_foreign'.$suffix)->assertNotFound();
        $missing = $this->withToken($key)->json($verb, '/api/v1/workspace/billing/payment-methods/pm_missing'.$suffix)->assertNotFound();
        expect($foreign->json('error'))->toBe($missing->json('error'))
            ->and($foreign->json('message'))->toBe($missing->json('message'));
    }
    expect($this->payments->paymentMethods('foreign-workspace'))->toHaveCount(1);
});

it('runs financial writes through organization actions and preserves the required default card', function (): void {
    ['subjectId' => $owner, 'organization' => $organization] = provisionAccount();
    signInAsMember($owner);
    $this->payments->withPaymentMethod($organization->id, new PaymentMethod('pm_first', 'visa', '4242', 12, 2030, false));
    $this->payments->withPaymentMethod($organization->id, new PaymentMethod('pm_second', 'visa', '1111', 12, 2030, false));
    $this->patch('/billing/payment-methods/pm_second/default', ['organization' => $organization->id])->assertRedirect()->assertSessionHasNoErrors();
    expect(collect($this->payments->paymentMethods($organization->id))->firstWhere('id', 'pm_second')->isDefault)->toBeTrue();
    $this->payments->withSubscription($organization->id, new Subscription('id-plan', 'active'));
    $this->payments->refusedRemovals = ['pm_second'];
    $this->delete('/billing/payment-methods/pm_second', ['organization' => $organization->id])->assertRedirect()->assertSessionHasErrors();
    $this->delete('/billing/payment-methods/pm_first', ['organization' => $organization->id])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->payments->paymentMethods($organization->id))->toHaveCount(1);
});

it('does not mistake an existing nondefault card for a newly confirmed card', function (): void {
    ['subjectId' => $owner, 'organization' => $organization] = provisionAccount();
    signInAsMember($owner);
    $this->payments->withPaymentMethod($organization->id, new PaymentMethod('pm_old', 'visa', '1111', 12, 2030, false));
    $this->postJson('/billing/payment-methods/setup', ['organization' => $organization->id])->assertOk();
    $this->postJson('/billing/payment-methods/complete', ['organization' => $organization->id])->assertStatus(409);
    $this->postJson('/billing/payment-methods/complete', ['organization' => $organization->id, 'payment_method' => 'pm_old'])->assertStatus(409);
    expect($this->payments->paymentMethods($organization->id)[0]->isDefault)->toBeFalse();
});
