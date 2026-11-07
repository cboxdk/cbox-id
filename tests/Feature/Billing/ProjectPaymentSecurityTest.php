<?php

declare(strict_types=1);

use App\Platform\Actions\ActionRefused;
use App\Platform\Billing\PaymentAccount;
use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\ValueObjects\Plan;
use Cbox\Id\Billing\Contracts\ProjectPayments;
use Cbox\Id\Billing\HttpProjectPayments;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['billing-client.base_url' => 'https://payments.test', 'billing-client.api_token' => 'project-test']);
    app()->bind(ProjectPayments::class, HttpProjectPayments::class);
    $this->cards = new FakeManagementTransport;
    app()->instance(BillingManagement::class, new BillingManagement($this->cards));
});

it('uses the central scoped detach decision and translates only the stable required-card refusal', function (int $status, array $body, string $expected, int $expectedStatus): void {
    Http::fake(fn ($request) => $request->method() === 'GET'
        ? Http::response(['payment_methods' => [['id' => 'pm_card', 'is_default' => true]]])
        : Http::response($body, $status));
    $billing = new BillingManagement(new HttpManagementTransport(
        app(Factory::class), 'https://payments.test', 'project-bound-token',
    ));
    try {
        (new PaymentAccount($billing))->remove('workspace', 'pm_card');
        test()->fail('The central refusal must prevent a successful removal.');
    } catch (ActionRefused $error) {
        expect($error->error)->toBe($expected)->and($error->status)->toBe($expectedStatus);
    }
    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://payments.test/api/v1/payment-methods/workspace/pm_card'
        && $request->hasHeader('Authorization', 'Bearer project-bound-token'));
    Http::assertSentCount(2);
})->with([
    'required by another product' => [422, ['error' => 'payment_method_required'], 'payment_method_required', 422],
    'unknown refusal' => [422, ['error' => 'unknown'], 'billing_unavailable', 503],
    'outage' => [503, [], 'billing_unavailable', 503],
    'revoked credential' => [403, ['error' => 'forbidden'], 'billing_unavailable', 503],
]);

it('accepts central permission to remove a card without duplicating subscription policy locally', function (): void {
    Http::fake(fn ($request) => $request->method() === 'GET'
        ? Http::response(['payment_methods' => [['id' => 'pm_card', 'is_default' => true]]])
        : Http::response('', 204));
    $billing = new BillingManagement(new HttpManagementTransport(
        app(Factory::class), 'https://payments.test', 'project-bound-token',
    ));
    (new PaymentAccount($billing))->remove('workspace', 'pm_card');
    Http::assertSentCount(2);
});

it('compares all quoted consequences and ignores only object key order and effective now', function (bool $changed): void {
    config(['billing.product' => 'cbox-id']);
    $account = provisionAccount();
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($account['subjectId'], 'owner@acme.example'));
    signInAsMember($account['subjectId']);
    $this->cards->withPlan(new Plan('id-start', 'Start', 10000, 'DKK', 'month', [], [], 'cbox-id'));
    $quoted = ['due_now_minor' => 10000, 'new_recurring_tax' => ['total_minor' => 12500],
        'seat_types' => [['key' => 'editor', 'carried' => 5, 'lapsing' => 0]], 'effective_at' => '2026-11-07T10:00:00Z'];
    $fresh = ['effective_at' => '2026-11-07T10:00:01Z',
        'seat_types' => [['lapsing' => $changed ? 5 : 0, 'carried' => $changed ? 0 : 5, 'key' => 'editor']],
        'new_recurring_tax' => ['total_minor' => 12500], 'due_now_minor' => 10000];
    $payments = Mockery::mock(ProjectPayments::class);
    $payments->shouldReceive('subscription')->twice()->with($account['organization']->id, $account['project']->id)
        ->andReturn(['status' => 'active', 'recurring' => ['currency' => 'DKK']]);
    $payments->shouldReceive('perform')->with('preview', $account['organization']->id, $account['project']->id, ['plan' => 'id-start'], Mockery::type('string'))
        ->twice()->andReturn($quoted, $fresh);
    if ($changed) {
        $payments->shouldNotReceive('perform')->with('change', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any());
    } else {
        $payments->shouldReceive('perform')->with('change', $account['organization']->id, $account['project']->id, ['plan' => 'id-start'], Mockery::type('string'))
            ->once()->andReturn(['ok' => true]);
    }
    app()->instance(ProjectPayments::class, $payments);
    $quote = $this->postJson(route('billing.preview', $account['project']->id), ['operation' => 'plan', 'plan' => 'id-start'])->assertOk()->json('quote');
    $this->postJson(route('billing.confirm', $account['project']->id), ['quote' => $quote])->assertStatus($changed ? 409 : 200);
})->with([true, false]);
