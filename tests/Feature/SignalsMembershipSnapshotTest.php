<?php

declare(strict_types=1);

use App\SignalsData\MembershipSnapshot;
use App\SignalsData\SourceLedger;
use Carbon\CarbonImmutable;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    installedDeployment();
    config(['signals-data.environments' => [], 'signals-auth.enabled' => false]);
});

it('observes bounded current memberships once and retracts from the observed start', function (): void {
    $team = app(Organizations::class)->create(new NewOrganization('Snapshot team', 'snapshot-team'));
    $members = [];
    foreach (range(1, 3) as $n) {
        $person = app(Subjects::class)->create('snapshot'.$n.'@example.test', 'Snapshot '.$n);
        $members[] = app(Memberships::class)->add($team->id, $person->id, MembershipRole::Member);
    }
    DB::table('memberships')->where('id', $members[2]->id)->update(['status' => 'suspended']);
    $foreign = (array) DB::table('memberships')->where('id', $members[0]->id)->first();
    $foreign['id'] = 'foreign-membership';
    $foreign['environment_id'] = 'env_other';
    $foreign['organization_id'] = 'foreign-team';
    DB::table('memberships')->insert($foreign);
    $this->travel(10)->minutes();
    $observed = CarbonImmutable::now();
    CarbonImmutable::setTestNow($observed);
    config(['signals-data.environments' => ['env_test']]);
    $snapshot = app(MembershipSnapshot::class);
    $first = $snapshot->page(null, 1);
    expect($first['scanned'])->toBe(1)->and($first['has_more'])->toBeTrue();
    $last = $snapshot->page($first['next'], 1);
    expect($last['scanned'])->toBe(1)->and($last['has_more'])->toBeFalse();
    $snapshot->page(null, 1000);
    $key = app(EnvironmentApiKeys::class)->issue('env_test', 'Snapshot qualification', ['signals_source:read'])->plaintext;
    $initial = collect($this->withToken($key)->getJson('/api/v1/signals/source')->assertOk()->json('data'));
    expect($initial)->toHaveCount(2);
    foreach ($initial as $row) {
        expect($row['kind'])->toBe('relation')->and($row['operation'])->toBe('assert')
            ->and($row['valid_from'])->toBe($observed->utc()->toISOString())
            ->and($row['to']['id'])->toBe($team->id);
    }
    $this->travel(5)->minutes();
    app(Memberships::class)->remove($team->id, $members[0]->user_id);
    $snapshot->page(null);
    $all = collect($this->withToken($key)->getJson('/api/v1/signals/source')->assertOk()->json('data'));
    expect($all)->toHaveCount(3);
    $retraction = $all->where('operation', 'retract')->sole();
    expect($retraction['valid_from'])->toBe($observed->utc()->toISOString())
        ->and($retraction['supersedes_id'])->toBe('membership:'.$members[0]->id.':assert');
});

it('refuses disabled owners and never rebuilds erased membership state', function (): void {
    $person = app(Subjects::class)->create('erased-member@example.test', 'Erased member');
    $team = app(Organizations::class)->create(new NewOrganization('Erased team', 'erased-team'));
    app(Memberships::class)->add($team->id, $person->id, MembershipRole::Member);
    $this->artisan('signals:source-memberships env_test')->assertFailed();
    config(['signals-data.environments' => ['env_test']]);
    app(SourceLedger::class)->erase([$person->id]);
    $this->artisan('signals:source-memberships env_test --limit=1')->assertSuccessful();
    expect(DB::table('signals_source_memberships')->count())->toBe(0)
        ->and(DB::table('signals_source_pending')->count())->toBe(0);
    $this->artisan('signals:source-memberships env_other')->assertFailed();
    $this->artisan('signals:source-memberships env_test --limit=1001')->assertFailed();
});

it('rolls back snapshot assertions together with their interval state', function (): void {
    $person = app(Subjects::class)->create('rolled-member@example.test', 'Rolled member');
    $team = app(Organizations::class)->create(new NewOrganization('Rolled team', 'rolled-team'));
    app(Memberships::class)->add($team->id, $person->id, MembershipRole::Member);
    config(['signals-data.environments' => ['env_test']]);
    DB::beginTransaction();
    app(MembershipSnapshot::class)->page(null);
    expect(DB::table('signals_source_memberships')->count())->toBe(1);
    DB::rollBack();
    expect(DB::table('signals_source_memberships')->count())->toBe(0)
        ->and(DB::table('signals_source_pending')->count())->toBe(0);
});
