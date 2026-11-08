<?php

declare(strict_types=1);

use App\Platform\Billing\CaptureProductUsage;
use App\Platform\Billing\UsageMeters;
use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Contracts\ManagementTransport;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Id\Kernel\Events\EventDelivered;
use Cbox\Id\Kernel\Events\Models\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function usageTransport(): FakeManagementTransport
{
    $transport = new FakeManagementTransport;
    foreach (UsageMeters::definitions() as $meter) {
        $transport->withUsageEventMeter($meter['key']);
    }
    app()->instance(ManagementTransport::class, $transport);
    app()->forgetInstance(BillingManagement::class);

    return $transport;
}

function deliverUsage(string $type, string $environmentId, array $payload = [], ?string $id = null): void
{
    app(CaptureProductUsage::class)->handle(new EventDelivered(new Event([
        'id' => $id ?? (string) Str::ulid(), 'type' => $type, 'environment_id' => $environmentId,
        'organization_id' => null, 'occurred_at' => now(), 'payload' => $payload,
    ])));
}

it('publishes exactly the meters it reports, every one fed by events', function (): void {
    $published = json_decode((string) file_get_contents(base_path('billing-definitions.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($published['meters'])->toBe(UsageMeters::definitions())
        ->and(collect($published['meters'])->pluck('source')->unique()->all())->toBe(['events'])
        ->and(collect($published['meters'])->pluck('key')->filter(fn (string $key): bool => str_starts_with($key, 'cbox-id.auth.'))->all())->toBe([]);
});

it('counts a sign-in by method and the person once towards monthly active users', function (): void {
    ['organization' => $workspace, 'environment' => $environment] = provisionAccount();

    deliverUsage('user.session_started', $environment->id, ['user_id' => 'usr_1', 'amr' => ['pwd', 'otp', 'mfa']], 'evt_a');
    deliverUsage('user.session_started', $environment->id, ['user_id' => 'usr_1', 'amr' => ['social', 'google']], 'evt_b');
    deliverUsage('user.session_started', $environment->id, ['user_id' => 'usr_1', 'amr' => ['handoff']], 'evt_c');
    deliverUsage('user.session_started', $environment->id, ['user_id' => 'usr_1', 'amr' => ['pwd']], 'evt_a');

    $rows = DB::table('product_billing_usage_outbox')->orderBy('event_id')->get();
    expect($rows->pluck('meter', 'event_id')->all())->toBe([
        'evt_a' => 'cbox-id.sign_ins', 'evt_a:active' => 'cbox-id.monthly_active_users',
        'evt_b' => 'cbox-id.sign_ins', 'evt_b:active' => 'cbox-id.monthly_active_users',
        'evt_c' => 'cbox-id.sign_ins', 'evt_c:active' => 'cbox-id.monthly_active_users',
    ])->and($rows->pluck('workspace_id')->unique()->all())->toBe([$workspace->id]);

    $methods = $rows->where('meter', 'cbox-id.sign_ins')->map(fn (object $row): string => json_decode($row->dimensions, true)['method'])->values()->all();
    expect($methods)->toBe(['password', 'social', 'handoff'])
        ->and($rows->where('meter', 'cbox-id.monthly_active_users')->pluck('unique_key')->unique()->values()->all())->toBe(['usr_1']);
});

it('measures each workspace stock and hourly activity once, never the platform root', function (): void {
    Carbon::setTestNow('2026-11-08 10:07:00');
    ['organization' => $workspace, 'environment' => $environment] = provisionAccount();
    $root = platformRootEnvironment();
    DB::table('product_billing_usage_outbox')->delete();

    foreach (['active', 'active', 'disabled'] as $i => $status) {
        DB::table('users')->insert(['id' => (string) Str::ulid(), 'environment_id' => $environment->id, 'email' => "u{$i}@acme.example",
            'name' => "U{$i}", 'password' => null, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('users')->insert(['id' => (string) Str::ulid(), 'environment_id' => $root->id, 'email' => 'operator@cbox.example',
        'name' => 'Operator', 'password' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('verified_domains')->insert([
        ['id' => (string) Str::ulid(), 'environment_id' => $environment->id, 'organization_id' => $workspace->id, 'domain' => 'acme.example',
            'verification_token' => 't1', 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) Str::ulid(), 'environment_id' => $environment->id, 'organization_id' => $workspace->id, 'domain' => 'pending.example',
            'verification_token' => 't2', 'verified_at' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('otp_challenges')->insert(['id' => (string) Str::ulid(), 'environment_id' => $environment->id, 'purpose' => 'sign_in',
        'channel' => 'sms', 'recipient' => '+4512345678', 'code_hash' => 'x', 'expires_at' => now()->addMinutes(5),
        'attempts' => 0, 'max_attempts' => 5, 'created_at' => '2026-11-08 09:30:00', 'updated_at' => now()]);

    $this->artisan('billing:snapshot-product-usage')->assertSuccessful();
    $this->artisan('billing:snapshot-product-usage')->assertSuccessful();

    $rows = DB::table('product_billing_usage_outbox')->get()->keyBy('event_id');
    expect($rows->pluck('workspace_id')->unique()->values()->all())->toBe([$workspace->id])
        ->and((int) $rows["stock:users:{$workspace->id}:2026110810"]->value)->toBe(2)
        ->and((int) $rows["stock:verified_domains:{$workspace->id}:2026110810"]->value)->toBe(1)
        ->and((int) $rows["stock:environments:{$workspace->id}:2026110810"]->value)->toBeGreaterThanOrEqual(1)
        ->and((int) $rows["hourly:otp_codes_sent:{$workspace->id}:2026110809"]->value)->toBe(1)
        ->and($rows->keys()->filter(fn (string $id): bool => str_starts_with($id, 'stock:users:'))->count())->toBe(1);
});

it('sends a workspace batch with its quantity, distinct key and dimensions and keeps the rejection reason', function (): void {
    ['organization' => $workspace, 'environment' => $environment] = provisionAccount();
    $transport = usageTransport();
    deliverUsage('user.session_started', $environment->id, ['user_id' => 'usr_9', 'amr' => ['pwd']], 'evt_x');
    deliverUsage('organization.invitation_created', $environment->id, [], 'evt_y');
    $transport->withUsageEventMeter('cbox-id.invitations_sent', 'archived_meter');

    $this->artisan('billing:report-product-usage')->assertFailed();

    $sent = collect($transport->recordedUsageEvents($workspace->id))->keyBy('id');
    expect($sent->keys()->sort()->values()->all())->toBe(['evt_x', 'evt_x:active'])
        ->and($sent['evt_x:active']['unique_key'])->toBe('usr_9')
        ->and($sent['evt_x']['dimensions'])->toEqual(['environment' => $environment->id, 'method' => 'password'])
        ->and(DB::table('product_billing_usage_outbox')->where('event_id', 'evt_y')->value('last_error'))->toBe('archived_meter')
        ->and(DB::table('product_billing_usage_outbox')->where('event_id', 'evt_y')->value('reported_at'))->toBeNull();

    $transport->withUsageEventMeter('cbox-id.invitations_sent');
    $this->artisan('billing:report-product-usage')->assertSuccessful();
    expect(DB::table('product_billing_usage_outbox')->whereNull('reported_at')->count())->toBe(0);
});
