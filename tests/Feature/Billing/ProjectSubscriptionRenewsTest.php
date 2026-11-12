<?php

declare(strict_types=1);

use App\Platform\Billing\ProjectSubscriptions;
use Cbox\Id\Billing\BilledProjectSubscriptions;
use Cbox\Id\Billing\Contracts\ProjectPayments;

/*
 * What a project's deletion asks of Billing: does a plan still RENEW on it? A subscription
 * that is cancelled, or set to end with its period, charges nobody again, so it does not
 * keep the project; anything else does.
 */

/** @param array<string, mixed>|null $subscription */
function billedProject(?array $subscription): ProjectSubscriptions
{
    config(['billing.enabled' => true, 'billing-client.base_url' => 'https://billing.test', 'billing-client.api_token' => 'token']);

    $payments = Mockery::mock(ProjectPayments::class);
    $payments->shouldReceive('subscription')->with('org_1', 'prj_1')->andReturn($subscription);

    return new BilledProjectSubscriptions($payments);
}

it('is what the billing module answers with', function (): void {
    expect(app(ProjectSubscriptions::class))->toBeInstanceOf(BilledProjectSubscriptions::class);
});

it('says whether a plan still renews on the project', function (?array $subscription, bool $renews): void {
    expect(billedProject($subscription)->renews('org_1', 'prj_1'))->toBe($renews);
})->with([
    'no subscription' => [null, false],
    'active' => [['status' => 'active'], true],
    'trialing' => [['status' => 'trialing'], true],
    'past due' => [['status' => 'past_due'], true],
    'a status this code has never seen' => [['status' => 'paused'], true],
    'no status at all' => [['plan' => 'pro'], true],
    'cancelled' => [['status' => 'canceled'], false],
    'ending with its period' => [['status' => 'active', 'cancel_at_period_end' => true], false],
]);

it('asks nobody where the deployment does not bill or has no Billing to ask', function (array $config): void {
    config(['billing.enabled' => true, 'billing-client.base_url' => 'https://billing.test', 'billing-client.api_token' => 'token']);
    config($config);

    $payments = Mockery::mock(ProjectPayments::class);
    $payments->shouldNotReceive('subscription');

    expect((new BilledProjectSubscriptions($payments))->renews('org_1', 'prj_1'))->toBeFalse();
})->with([
    'billing off' => [['billing.enabled' => false]],
    'no billing address' => [['billing-client.base_url' => null]],
    'no billing token' => [['billing-client.api_token' => '']],
]);
