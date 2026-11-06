<?php

declare(strict_types=1);

use App\SignalsData\MembershipSnapshot;
use App\SignalsData\SourceFact;
use App\SignalsData\SourceLedger;
use Carbon\CarbonImmutable;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires real PostgreSQL transactions.');
    }
    config(['signals-data.environments' => ['env_test']]);
});

function sourceWriter(): PDO
{
    $configuration = config('database.connections.pgsql');

    return new PDO('pgsql:host='.$configuration['host'].';port='.$configuration['port'].';dbname='.$configuration['database'], $configuration['username'], $configuration['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function stageSourceFact(PDO $writer, string $id): void
{
    $context = ['device_id' => null, 'device_evidence' => 'missing', 'ip' => null, 'country' => null, 'asn' => null,
        'browser_family' => null, 'browser_major' => null, 'os_family' => null, 'network_class' => 'unknown',
        'latitude' => null, 'longitude' => null, 'geo_accuracy_km' => null, 'enrichment_version' => 'qualification'];
    $data = ['subject' => ['namespace' => 'cbox-id:env_test', 'type' => 'user', 'id' => $id], 'action' => 'created', 'attempt_id' => null, 'context' => $context, 'actor' => null];
    $statement = $writer->prepare('INSERT INTO signals_source_pending(environment_id,id,name,occurred_at,recorded_at,data,subject_hashes) VALUES(?,?,?,?,?,?,?)');
    $statement->execute(['env_test', $id, 'identity.account', '2026-11-06T10:00:00Z', now()->toISOString(), json_encode($data), json_encode([hash('sha256', $id)])]);
}

it('never advances its public cursor past an uncommitted fact', function (): void {
    $key = app(EnvironmentApiKeys::class)->issue('env_test', 'Commit ordering qualification', ['signals_source:read'])->plaintext;
    $delayed = sourceWriter();
    $early = sourceWriter();
    $delayed->beginTransaction();
    stageSourceFact($delayed, 'a-created-earlier-committed-later');
    $early->beginTransaction();
    stageSourceFact($early, 'b-committed-first');
    $early->commit();

    $first = $this->withToken($key)->getJson('/api/v1/signals/source?limit=1')->assertOk()->assertJsonCount(1, 'data');
    expect($first->json('data.0.id'))->toBe('b-committed-first');
    $delayed->commit();
    $after = urlencode($first->json('meta.next_cursor'));
    $second = $this->withToken($key)->getJson('/api/v1/signals/source?limit=1&after='.$after)->assertOk()->assertJsonCount(1, 'data');
    expect($second->json('data.0.id'))->toBe('a-created-earlier-committed-later')
        ->and((int) $second->json('data.0.position.sequence'))->toBeGreaterThan((int) $first->json('data.0.position.sequence'));
    $retry = $this->withToken($key)->getJson('/api/v1/signals/source?limit=1&after='.$after)->assertOk();
    expect($retry->json('data'))->toBe($second->json('data'));
    $this->withToken($key)->getJson('/api/v1/signals/source?after='.urlencode($second->json('meta.next_cursor')))->assertOk()->assertJsonCount(0, 'data');
});

it('serializes erasure with committed owner facts and suppresses later replays', function (): void {
    $ledger = app(SourceLedger::class);
    $other = sourceWriter();
    $lock = $other->prepare('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0))');
    $identity = 'privacy-race-subject';
    $fact = new SourceFact('race-fact', 'identity.account', ['action' => 'created'], CarbonImmutable::now(), [$identity]);
    DB::beginTransaction();
    try {
        $ledger->append($fact);
        $other->beginTransaction();
        $lock->execute(['id-source/env_test/'.hash('sha256', $identity)]);
        expect($lock->fetchColumn())->toBeFalse();
        $lock->execute(['id-source/env_test/'.hash('sha256', 'unrelated-subject')]);
        expect($lock->fetchColumn())->toBeTrue();
        $other->rollBack();
        DB::commit();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($other->inTransaction()) {
            $other->rollBack();
        }
    }
    DB::beginTransaction();
    try {
        $ledger->erase([$identity]);
        $other->beginTransaction();
        $shared = $other->prepare('SELECT pg_try_advisory_xact_lock_shared(hashtextextended(?, 0))');
        $shared->execute(['id-source/env_test/'.hash('sha256', $identity)]);
        expect($shared->fetchColumn())->toBeFalse();
        $other->rollBack();
        DB::commit();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($other->inTransaction()) {
            $other->rollBack();
        }
    }
    $ledger->append($fact);
    expect(DB::table('signals_source_pending')->where('id', 'race-fact')->exists())->toBeFalse();
});

it('serializes a membership snapshot with owner deletion and preserves its retraction', function (): void {
    config(['signals-data.environments' => []]);
    $person = app(Subjects::class)->create('locked-snapshot@example.test', 'Locked snapshot');
    $team = app(Organizations::class)->create(new NewOrganization('Locked team', 'locked-team'));
    $membership = app(Memberships::class)->add($team->id, $person->id, MembershipRole::Member);
    config(['signals-data.environments' => ['env_test']]);
    $other = sourceWriter();
    $other->exec("SET lock_timeout='80ms'");
    DB::beginTransaction();
    try {
        app(MembershipSnapshot::class)->page(null);
        $delete = $other->prepare('DELETE FROM memberships WHERE environment_id=? AND id=?');
        try {
            $delete->execute(['env_test', $membership->id]);
            $this->fail('Owner deletion must wait for the observed membership assertion.');
        } catch (PDOException $exception) {
            expect($exception->getCode())->toBe('55P03');
        }
        DB::commit();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
    app(Memberships::class)->remove($team->id, $person->id);
    app(MembershipSnapshot::class)->page(null);
    $key = app(EnvironmentApiKeys::class)->issue('env_test', 'Membership race qualification', ['signals_source:read'])->plaintext;
    $records = collect($this->withToken($key)->getJson('/api/v1/signals/source')->assertOk()->json('data'));
    expect($records)->toHaveCount(2)
        ->and($records->where('operation', 'assert'))->toHaveCount(1)
        ->and($records->where('operation', 'retract'))->toHaveCount(1);
    $assertion = $records->where('operation', 'assert')->sole();
    $retraction = $records->where('operation', 'retract')->sole();
    expect($retraction['supersedes_id'])->toBe($assertion['id'])
        ->and($retraction['valid_from'])->toBe($assertion['valid_from']);
});
