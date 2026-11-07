<?php

use Cbox\Signals\Contracts\ManagedSources;
use Cbox\Signals\Data\EnrollmentStatus;
use Cbox\Signals\Data\IssuedSecret;
use Cbox\Signals\Data\ManagedEnrollment;
use Cbox\Signals\Data\ManagedEnvironment;
use Cbox\Signals\Exceptions\SignalsFailure;
use Cbox\Signals\Managed\ConfiguredManagedCredential;
use Cbox\Signals\Managed\HttpManagedIntegration;
use Cbox\Signals\Managed\ManagedEnroller;
use Cbox\Signals\Support\JsonTransport;
use Cbox\Signals\Testing\FakeManagedIntegration;
use Illuminate\Http\Client\Factory;
use Opis\JsonSchema\Validator;

function enrollmentWire(string $owner, string $status = 'enrolled', string $url = 'https://id.test/api/v1/signals/source'): array
{
    return ['provider' => 'cbox_id', 'owner_id' => $owner, 'source' => 'managed-id-'.$owner, 'url' => $url, 'catalog_version' => 1, 'status' => $status,
        'import_from' => '2026-11-01T00:00:00Z', 'enrolled_at' => '2026-11-07T12:00:00Z', 'updated_at' => '2026-11-07T12:00:00Z', 'pipelines_generation' => '', 'error_code' => ''];
}

function managedEnvironment(string $owner = 'env_1'): ManagedEnvironment
{
    return new ManagedEnvironment($owner, 'org_1', 'https://id.test/api/v1/signals/source', new DateTimeImmutable('2026-11-01T00:00:00Z'));
}

function httpManaged(Factory $http, string $token = 'machine-token'): HttpManagedIntegration
{
    return new HttpManagedIntegration(new JsonTransport($http, 'https://signals.test'), new ConfiguredManagedCredential($token));
}

final class RecordingSources implements ManagedSources
{
    /** @var list<ManagedEnvironment> */
    public array $environments = [];

    /** @var list<string> */
    public array $retired = [];

    public int $issued = 0;

    /** @var list<array{owner:string,keep:string}> */
    public array $retirements = [];

    public function provider(): string
    {
        return 'cbox_id';
    }

    public function environments(): iterable
    {
        return $this->environments;
    }

    public function retired(): iterable
    {
        return $this->retired;
    }

    public function issueSecret(ManagedEnvironment $environment): IssuedSecret
    {
        $this->issued++;

        return new IssuedSecret('plain-'.$this->issued, 'key-'.$this->issued);
    }

    public function retireSecrets(ManagedEnvironment $environment, string $keepReference): void
    {
        $this->retirements[] = ['owner' => $environment->ownerId, 'keep' => $keepReference];
    }

    /** @var list<array{owner:string,reference:string}> */
    public array $discards = [];

    public function discardSecret(ManagedEnvironment $environment, string $reference): void
    {
        $this->discards[] = ['owner' => $environment->ownerId, 'reference' => $reference];
    }
}

it('enrols with the machine credential and transports the secret exactly once', function (): void {
    $http = new Factory;
    $http->fake(['https://signals.test/*' => Factory::response(enrollmentWire('env_1'))]);
    $enrollment = httpManaged($http)->enroll('cbox_id', managedEnvironment(), 'plain-secret');
    expect($enrollment->status)->toBe(EnrollmentStatus::Enrolled)->and($enrollment->source)->toBe('managed-id-env_1')->and($enrollment->pipelinesGeneration)->toBeNull();
    $http->assertSent(fn ($request) => $request->method() === 'PUT'
        && $request->url() === 'https://signals.test/v1/managed-integrations/cbox_id/enrollments/env_1'
        && $request->hasHeader('Authorization', 'Bearer machine-token')
        && $request['secret'] === 'plain-secret' && $request['organization'] === 'org_1' && $request['url'] === 'https://id.test/api/v1/signals/source'
        && $request['import_from'] === '2026-11-01T00:00:00.000+00:00');
    $http->assertSentCount(1);
});

it('distinguishes never enrolled from unavailable and surfaces server codes', function (): void {
    $http = new Factory;
    $http->fake(['*' => Factory::response(['code' => 'not_enrolled'], 404)]);
    expect(httpManaged($http)->enrollment('cbox_id', 'env_9'))->toBeNull();

    $http = new Factory;
    $http->fake(['*' => Factory::response(['code' => 'enrollment_conflict'], 409)]);
    try {
        httpManaged($http)->enroll('cbox_id', managedEnvironment(), 'plain');
        $this->fail('conflict accepted');
    } catch (SignalsFailure $failure) {
        expect($failure->errorCode)->toBe('enrollment_conflict')->and($failure->status)->toBe(409);
    }

    $http = new Factory;
    $http->fake(['*' => Factory::response(null, 503)]);
    expect(fn () => httpManaged($http)->enrollment('cbox_id', 'env_1'))->toThrow(SignalsFailure::class, 'unavailable');
    expect(fn () => httpManaged(new Factory, '')->enrollment('cbox_id', 'env_1'))->toThrow(SignalsFailure::class, 'not_configured');
});

it('refuses unsafe identifiers, empty secrets and malformed replies before or after the wire', function (): void {
    $http = new Factory;
    $http->fake(['*' => Factory::response(enrollmentWire('env_1'))]);
    expect(fn () => httpManaged($http)->enrollment('cbox_id', '../other'))->toThrow(SignalsFailure::class, 'invalid_identifier');
    expect(fn () => httpManaged($http)->enroll('cbox_id', managedEnvironment(), ''))->toThrow(SignalsFailure::class, 'secret_required');
    $http->assertNothingSent();

    $http = new Factory;
    $http->fake(['*' => Factory::response([...enrollmentWire('env_1'), 'status' => 'paused'])]);
    expect(fn () => httpManaged($http)->enrollment('cbox_id', 'env_1'))->toThrow(SignalsFailure::class, 'malformed_response');
});

it('treats revoking an unknown enrolment as done and other failures as failures', function (): void {
    $http = new Factory;
    $http->fake(['*' => Factory::response(['code' => 'not_enrolled'], 404)]);
    httpManaged($http)->revoke('cbox_id', 'env_1');
    $http->assertSent(fn ($request) => $request->method() === 'DELETE');

    $http = new Factory;
    $http->fake(['*' => Factory::response(['code' => 'forbidden'], 403)]);
    expect(fn () => httpManaged($http)->revoke('cbox_id', 'env_1'))->toThrow(SignalsFailure::class, 'forbidden');
});

it('reconciles idempotently: enrols missing, leaves matching alone, rotates on request, revokes retired', function (): void {
    $signals = new FakeManagedIntegration;
    $sources = new RecordingSources;
    $sources->environments = [managedEnvironment('env_1'), managedEnvironment('env_2')];
    $enroller = new ManagedEnroller($signals, $sources);

    $first = $enroller->reconcile();
    expect($first->enrolled)->toBe(['env_1', 'env_2'])->and($first->unchanged)->toBe([])->and($sources->issued)->toBe(2)
        ->and($sources->retirements)->toBe([['owner' => 'env_1', 'keep' => 'key-1'], ['owner' => 'env_2', 'keep' => 'key-2']]);

    $second = $enroller->reconcile();
    expect($second->unchanged)->toBe(['env_1', 'env_2'])->and($second->enrolled)->toBe([])->and($sources->issued)->toBe(2)->and($second->clean())->toBeTrue();

    $third = $enroller->reconcile(rotate: ['env_2']);
    expect($third->rotated)->toBe(['env_2'])->and($third->unchanged)->toBe(['env_1'])->and($sources->issued)->toBe(3)->and($sources->retirements[2])->toBe(['owner' => 'env_2', 'keep' => 'key-3']);

    $sources->environments = [managedEnvironment('env_1')];
    $sources->retired = ['env_2'];
    $fourth = $enroller->reconcile();
    expect($fourth->revoked)->toBe(['env_2'])->and($signals->enrollments['cbox_id/env_2']->status)->toBe(EnrollmentStatus::Revoked);

    $sources->environments = [new ManagedEnvironment('env_1', 'org_1', 'https://id.test/other', new DateTimeImmutable('2026-11-01T00:00:00Z'))];
    $sources->retired = [];
    $fifth = $enroller->reconcile();
    expect($fifth->rotated)->toBe(['env_1'])->and($signals->enrollments['cbox_id/env_1']->url)->toBe('https://id.test/other');
});

it('isolates failures per environment, discards the unaccepted secret and keeps the old ones', function (): void {
    $signals = (new FakeManagedIntegration)->failWith(new SignalsFailure('organization_unknown', 404), ['enroll', 'revoke']);
    $sources = new RecordingSources;
    $sources->environments = [managedEnvironment('env_1')];
    $sources->retired = ['env_0'];
    $result = (new ManagedEnroller($signals, $sources))->reconcile();
    expect($result->failed)->toBe(['env_1' => 'organization_unknown', 'env_0' => 'organization_unknown'])->and($result->clean())->toBeFalse()->and($sources->retirements)->toBe([])
        ->and($sources->discards)->toBe([['owner' => 'env_1', 'reference' => 'key-1']]);

    $signals = (new FakeManagedIntegration)->failWith(new SignalsFailure('unavailable', 503), ['enrollment']);
    $sources = new RecordingSources;
    $sources->environments = [managedEnvironment('env_1')];
    $result = (new ManagedEnroller($signals, $sources))->reconcile();
    expect($result->failed)->toBe(['env_1' => 'unavailable'])->and($sources->issued)->toBe(0);
});

it('matches the shared managed enrolment wire contract in both directions', function (): void {
    $document = json_decode(file_get_contents(__DIR__.'/Contracts/schemas.json'), flags: JSON_THROW_ON_ERROR);
    $request = clone $document;
    $request->{'$ref'} = '#/$defs/ManagedEnrollmentRequest';
    $wire = json_decode(json_encode([...managedEnvironment()->toArray(), 'secret' => 'cbid_env_test_only_secret_0123456789'], JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
    expect((new Validator)->validate($wire, $request)->isValid())->toBeTrue();

    $fixtures = json_decode(file_get_contents(__DIR__.'/Contracts/fixtures.json'), true, flags: JSON_THROW_ON_ERROR);
    $seen = 0;
    foreach ($fixtures as $fixture) {
        if ($fixture['schema'] !== 'ManagedEnrollment') {
            continue;
        }
        $seen++;
        if ($fixture['valid']) {
            expect(ManagedEnrollment::fromArray($fixture['data'])->ownerId)->toBe($fixture['data']['owner_id']);
        } else {
            expect(fn () => ManagedEnrollment::fromArray($fixture['data']))->toThrow(SignalsFailure::class);
        }
    }
    expect($seen)->toBeGreaterThanOrEqual(4);
});
