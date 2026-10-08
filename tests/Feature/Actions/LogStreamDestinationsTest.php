<?php

declare(strict_types=1);

use Cbox\Id\AuditStreaming\Models\AuditStream;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\LaravelSiem\Contracts\LogStreams;
use Cbox\LaravelSiem\Enums\AuthScheme;
use Cbox\LaravelSiem\Enums\Destination;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| Log streams to Datadog, Amazon S3 and Google Cloud Storage.
|--------------------------------------------------------------------------
|
| The three cloud destinations, end to end through the actions: created, tested and
| updated over the management API and the console, with the HTTP fake standing in for each
| vendor. The wire format is the SIEM package's to prove; what is proved here is that the
| platform hands it the right settings and credential, maps its refusals onto the field
| they are about, never sends a credential back, and says how each stream is doing.
*/

/** @param  list<string>  $scopes */
function cloudStreamKey(array $scopes = ['log_streams:read', 'log_streams:write']): string
{
    return app(EnvironmentApiKeys::class)->issue('env_test', 'SIEM worker', $scopes)->plaintext;
}

/** The platform's own AWS identity, so an S3 stream may assume a customer's role. */
function platformAwsIdentity(): void
{
    config([
        'siem.aws.access_key_id' => 'AKIAPLATFORMEXAMPLE',
        'siem.aws.secret_access_key' => 'platform-secret-access-key',
        'cbox-id.log_streams.aws_principal_arn' => 'arn:aws:iam::111122223333:user/cbox-siem',
    ]);
}

/** A real (throwaway) service-account key file — the package parses and signs with it. */
function gcsServiceAccountKey(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);

    return (string) json_encode([
        'type' => 'service_account',
        'client_email' => 'siem-writer@acme-audit.iam.gserviceaccount.com',
        'private_key' => $pem,
        'private_key_id' => 'key-1',
        // Ignored on purpose: a key file must not choose where the platform sends requests.
        'token_uri' => 'https://token.attacker.example/token',
    ]);
}

/** STS's answer to AssumeRole: short-lived credentials for the customer's role. */
function stsAssumeRoleAnswer(): string
{
    return '<AssumeRoleResponse xmlns="https://sts.amazonaws.com/doc/2011-06-15/"><AssumeRoleResult><Credentials>'
        .'<AccessKeyId>ASIATEMPORARYEXAMPLE</AccessKeyId><SecretAccessKey>temporary-secret</SecretAccessKey>'
        .'<SessionToken>temporary-session-token</SessionToken><Expiration>2099-01-01T00:00:00Z</Expiration>'
        .'</Credentials></AssumeRoleResult></AssumeRoleResponse>';
}

/** @return array<string, mixed> */
function s3AccessKeyStream(array $changes = []): array
{
    return [
        'name' => 'Audit archive',
        'destination' => 's3',
        'secret' => 'the-secret-access-key',
        'options' => ['bucket' => 'acme-audit', 'region' => 'eu-west-1', 'prefix' => 'cbox/audit', 'access_key_id' => 'AKIACUSTOMEREXAMPLE'],
        'environment_wide' => true,
        ...$changes,
    ];
}

// ── Datadog ─────────────────────────────────────────────────────────────────

it('streams to Datadog: the site picks the intake, the API key is the secret and never comes back', function (): void {
    Http::fake(['http-intake.logs.datadoghq.eu/*' => Http::response(['status' => 'ok'], 202)]);
    $key = cloudStreamKey();

    $created = $this->withToken($key)->postJson('/api/v1/log-streams', [
        'name' => 'Datadog EU',
        'destination' => 'datadog',
        'secret' => 'dd-api-key-0123456789',
        'options' => ['site' => 'datadoghq.eu', 'service' => 'acme-id', 'tags' => ['env:prod', 'team:security']],
        'environment_wide' => true,
    ])->assertCreated();

    expect($created->json('data'))->toMatchArray([
        'destination' => 'datadog',
        'endpoint_url' => 'https://http-intake.logs.datadoghq.eu/api/v2/logs',
        'auth' => 'none',
        'options' => ['site' => 'datadoghq.eu', 'service' => 'acme-id', 'tags' => 'env:prod,team:security'],
        'external_id' => null,
        'health' => 'healthy',
        'last_error' => null,
        'last_failure_kind' => null,
    ])
        ->and($created->json('data'))->not->toHaveKey('secret')
        ->and((string) $created->getContent())->not->toContain('dd-api-key-0123456789');

    $id = $created->json('data.id');

    $this->withToken($key)->postJson("/api/v1/log-streams/{$id}/test")
        ->assertOk()
        ->assertJsonPath('data.delivered', true)
        ->assertJsonPath('data.failure', null);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://http-intake.logs.datadoghq.eu/api/v2/logs'
        && $request->header('DD-API-KEY') === ['dd-api-key-0123456789']);

    [$entry] = array_values(AuditEntry::query()->where('action', 'log_stream.created')->get()->all());

    expect($entry->context['options'])->toBe(['site' => 'datadoghq.eu', 'service' => 'acme-id', 'tags' => 'env:prod,team:security'])
        ->and((string) json_encode($entry->context))->not->toContain('dd-api-key');
});

it('reports a refused API key as the reason, and a later key update resets the stream', function (): void {
    Http::fake(['http-intake.logs.*' => Http::response(['errors' => ['Forbidden']], 403)]);
    $key = cloudStreamKey();

    $id = $this->withToken($key)->postJson('/api/v1/log-streams', [
        'name' => 'Datadog', 'destination' => 'datadog', 'secret' => 'revoked-api-key', 'environment_wide' => true,
    ])->assertCreated()->json('data.id');

    $test = $this->withToken($key)->postJson("/api/v1/log-streams/{$id}/test")->assertOk();

    expect($test->json('data.delivered'))->toBeFalse()
        ->and($test->json('data.failure'))->toBe('authentication')
        ->and($test->json('data.error'))->toBeString()->not->toContain('revoked-api-key');

    // What the pump records when the intake refuses the key: the stream needs a person.
    AuditStream::query()->whereKey($id)->update([
        'consecutive_failures' => 1,
        'last_failure_kind' => 'authentication',
        'last_error' => 'Datadog refused the API key (HTTP 403).',
        'last_failure_at' => now(),
        'circuit_opened_at' => now(),
    ]);

    $this->withToken($key)->getJson("/api/v1/log-streams/{$id}")
        ->assertOk()
        ->assertJsonPath('data.health', 'action_required')
        ->assertJsonPath('data.last_failure_kind', 'authentication')
        ->assertJsonPath('data.last_error', 'Datadog refused the API key (HTTP 403).');

    $rotated = $this->withToken($key)->patchJson("/api/v1/log-streams/{$id}", ['secret' => 'the-new-api-key'])
        ->assertOk()
        ->assertJsonPath('data.health', 'healthy')
        ->assertJsonPath('data.consecutive_failures', 0);

    expect((string) $rotated->getContent())->not->toContain('the-new-api-key')
        ->and(AuditStream::query()->findOrFail($id)->secret)->toBe('the-new-api-key');

    [$updated] = array_values(AuditEntry::query()->where('action', 'log_stream.updated')->get()->all());

    expect($updated->context['changed'])->toBe(['secret'])
        ->and((string) json_encode($updated->context))->not->toContain('the-new-api-key');
});

it('moves a Datadog stream to another site: the endpoint follows, the rest of its options stay', function (): void {
    $key = cloudStreamKey();

    $id = $this->withToken($key)->postJson('/api/v1/log-streams', [
        'name' => 'Datadog', 'destination' => 'datadog', 'secret' => 'dd-key', 'options' => ['service' => 'acme'], 'environment_wide' => true,
    ])->assertCreated()->assertJsonPath('data.endpoint_url', 'https://http-intake.logs.datadoghq.com/api/v2/logs')->json('data.id');

    $this->withToken($key)->patchJson("/api/v1/log-streams/{$id}", ['options' => ['site' => 'us5.datadoghq.com']])
        ->assertOk()
        ->assertJsonPath('data.endpoint_url', 'https://http-intake.logs.us5.datadoghq.com/api/v2/logs')
        ->assertJsonPath('data.options', ['site' => 'us5.datadoghq.com', 'service' => 'acme']);

    // To an HTTP collector, the cloud settings and the API key do not come along — and a
    // collector needs a URL.
    $this->withToken($key)->patchJson("/api/v1/log-streams/{$id}", ['destination' => 'generic_json'])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'invalid_stream_configuration');

    $this->withToken($key)->patchJson("/api/v1/log-streams/{$id}", ['destination' => 'generic_json', 'endpoint_url' => 'https://siem.acme.example/in', 'auth' => 'none'])
        ->assertOk()
        ->assertJsonPath('data.destination', 'generic_json')
        ->assertJsonPath('data.options', null)
        ->assertJsonPath('data.auth', 'none');

    expect(AuditStream::query()->findOrFail($id)->secret)->toBeNull();
});

// ── Amazon S3 ───────────────────────────────────────────────────────────────

it('writes to an S3 bucket with an access key, signed, and never echoes the secret access key', function (): void {
    Http::fake(['s3.eu-west-1.amazonaws.com/*' => Http::response('', 200)]);
    $key = cloudStreamKey();

    $created = $this->withToken($key)->withHeader('Idempotency-Key', 's3-1')->postJson('/api/v1/log-streams', s3AccessKeyStream())->assertCreated();
    $replayed = $this->withToken($key)->withHeader('Idempotency-Key', 's3-1')->postJson('/api/v1/log-streams', s3AccessKeyStream())->assertCreated();

    expect($created->json('data.endpoint_url'))->toBe('https://s3.eu-west-1.amazonaws.com')
        ->and($created->json('data.options'))->toBe(['bucket' => 'acme-audit', 'region' => 'eu-west-1', 'prefix' => 'cbox/audit', 'access_key_id' => 'AKIACUSTOMEREXAMPLE', 'gzip' => true])
        ->and($created->json('data.external_id'))->toBeNull()
        ->and((string) $created->getContent())->not->toContain('the-secret-access-key')
        ->and((string) $replayed->getContent())->not->toContain('the-secret-access-key');

    $this->flushHeaders()->withToken($key)->postJson('/api/v1/log-streams/'.$created->json('data.id').'/test')
        ->assertOk()->assertJsonPath('data.delivered', true);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_starts_with($request->url(), 'https://acme-audit.s3.eu-west-1.amazonaws.com/cbox/audit/')
        && str_starts_with($request->header('Authorization')[0] ?? '', 'AWS4-HMAC-SHA256 Credential=AKIACUSTOMEREXAMPLE/'));
});

it('assumes the customer\'s role with the stream\'s own external ID, and keeps that ID across edits', function (): void {
    platformAwsIdentity();
    Http::fake([
        'sts.amazonaws.com*' => Http::response(stsAssumeRoleAnswer(), 200, ['Content-Type' => 'text/xml']),
        's3.eu-west-1.amazonaws.com/*' => Http::response('', 200),
    ]);
    $key = cloudStreamKey();

    $created = $this->withToken($key)->postJson('/api/v1/log-streams', s3AccessKeyStream([
        'secret' => null,
        'options' => ['bucket' => 'acme-audit', 'region' => 'eu-west-1', 'role_arn' => 'arn:aws:iam::444455556666:role/cbox-writer'],
    ]))->assertCreated();

    $id = $created->json('data.id');
    $externalId = $created->json('data.external_id');

    expect($externalId)->toBeString()->toMatch('/^[0-9a-f]{32}$/')
        ->and($created->json('data.options.external_id'))->toBe($externalId)
        ->and($created->json('data'))->not->toHaveKey('secret')
        ->and(AuditStream::query()->findOrFail($id)->secret)->toBeNull();

    $this->withToken($key)->postJson("/api/v1/log-streams/{$id}/test")->assertOk()->assertJsonPath('data.delivered', true);

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://sts.amazonaws.com')
        && str_contains($request->body(), 'ExternalId='.$externalId)
        && str_contains($request->body(), 'RoleArn='.rawurlencode('arn:aws:iam::444455556666:role/cbox-writer')));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->header('X-Amz-Security-Token') === ['temporary-session-token']);

    // A new prefix is not a new trust relationship: the ID the customer pasted stays.
    $this->withToken($key)->patchJson("/api/v1/log-streams/{$id}", ['options' => ['prefix' => 'audit/v2']])
        ->assertOk()
        ->assertJsonPath('data.external_id', $externalId)
        ->assertJsonPath('data.options.prefix', 'audit/v2');
});

it('moves an S3 stream from an access key to an assumed role, dropping the stored key', function (): void {
    platformAwsIdentity();
    $key = cloudStreamKey();
    $id = $this->withToken($key)->postJson('/api/v1/log-streams', s3AccessKeyStream())->assertCreated()->json('data.id');

    $moved = $this->withToken($key)->patchJson("/api/v1/log-streams/{$id}", [
        'options' => ['access_key_id' => null, 'role_arn' => 'arn:aws:iam::444455556666:role/cbox-writer'],
    ])->assertOk();

    expect($moved->json('data.external_id'))->toBeString()->toMatch('/^[0-9a-f]{32}$/')
        ->and($moved->json('data.options'))->not->toHaveKey('access_key_id')
        ->and(AuditStream::query()->findOrFail($id)->secret)->toBeNull();
});

it('refuses an assumed role when the platform has no AWS identity to assume it with', function (): void {
    $this->withToken(cloudStreamKey())->postJson('/api/v1/log-streams', s3AccessKeyStream([
        'secret' => null,
        'options' => ['bucket' => 'acme-audit', 'region' => 'eu-west-1', 'role_arn' => 'arn:aws:iam::444455556666:role/cbox-writer'],
    ]))->assertUnprocessable()->assertJsonPath('error', 'assumed_role_unavailable');

    expect(AuditStream::query()->count())->toBe(0);
});

// ── Google Cloud Storage ────────────────────────────────────────────────────

it('writes to a GCS bucket with a service-account key, exchanged at Google and never at the key file\'s address', function (): void {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test-token', 'expires_in' => 3600, 'token_type' => 'Bearer']),
        'storage.googleapis.com/*' => Http::response(['name' => 'object'], 200),
    ]);
    $key = cloudStreamKey();
    $serviceAccount = gcsServiceAccountKey();

    $created = $this->withToken($key)->postJson('/api/v1/log-streams', [
        'name' => 'GCS archive',
        'destination' => 'gcs',
        'secret' => $serviceAccount,
        'options' => ['bucket' => 'acme-audit', 'prefix' => 'cbox', 'gzip' => false],
        'environment_wide' => true,
    ])->assertCreated();

    expect($created->json('data.endpoint_url'))->toBe('https://storage.googleapis.com')
        ->and($created->json('data.options'))->toBe(['bucket' => 'acme-audit', 'prefix' => 'cbox', 'gzip' => false])
        ->and((string) $created->getContent())->not->toContain('PRIVATE KEY')
        ->and((string) $created->getContent())->not->toContain('siem-writer@');

    $this->withToken($key)->postJson('/api/v1/log-streams/'.$created->json('data.id').'/test')
        ->assertOk()->assertJsonPath('data.delivered', true);

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://storage.googleapis.com/upload/storage/v1/b/acme-audit/o')
        && $request->header('Authorization') === ['Bearer ya29.test-token']);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'attacker.example'));
});

// ── Refusals, on the field they are about ───────────────────────────────────

it('refuses settings a destination does not take, before anything is stored', function (array $payload, string $because): void {
    platformAwsIdentity();

    $this->withToken(cloudStreamKey())->postJson('/api/v1/log-streams', ['name' => 'S', 'environment_wide' => true, ...$payload])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'invalid_stream_configuration')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, $because));

    expect(AuditStream::query()->count())->toBe(0);
})->with([
    'an S3 stream without a bucket' => [['destination' => 's3', 'secret' => 'k', 'options' => ['region' => 'eu-west-1', 'access_key_id' => 'AKIAEXAMPLE']], 'bucket'],
    'an S3 stream with both credentials' => [['destination' => 's3', 'secret' => 'k', 'options' => ['bucket' => 'acme-audit', 'region' => 'eu-west-1', 'access_key_id' => 'AKIAEXAMPLE', 'role_arn' => 'arn:aws:iam::444455556666:role/x']], 'exactly one'],
    'a Datadog stream without an API key' => [['destination' => 'datadog'], 'API key'],
    'a GCS stream whose secret is not a key file' => [['destination' => 'gcs', 'secret' => 'not json', 'options' => ['bucket' => 'acme-audit']], 'service-account'],
    'an HTTP collector given options' => [['destination' => 'generic_json', 'endpoint_url' => 'https://siem.acme.example/in', 'options' => ['bucket' => 'acme-audit']], 'takes no options'],
    'a custom endpoint over http' => [['destination' => 's3', 'secret' => 'k', 'endpoint_url' => 'http://minio.acme.example', 'options' => ['bucket' => 'acme-audit', 'region' => 'us-east-1', 'access_key_id' => 'AKIAEXAMPLE']], 'https'],
]);

it('refuses an option key no destination has, at the door', function (): void {
    $this->withToken(cloudStreamKey())->postJson('/api/v1/log-streams', [
        'name' => 'S', 'destination' => 's3', 'secret' => 'k', 'environment_wide' => true,
        'options' => ['bucket' => 'acme-audit', 'region' => 'eu-west-1', 'access_key_id' => 'AKIAEXAMPLE', 'external_id' => 'chosen-by-the-caller'],
    ])->assertUnprocessable()->assertJsonPath('error', 'validation_failed');
});

// ── The console ─────────────────────────────────────────────────────────────

it('creates, shows, tests and edits a Datadog stream in the console without echoing its key', function (): void {
    Http::fake(['http-intake.logs.*' => Http::response(['status' => 'ok'], 202)]);
    actingAsRole(MembershipRole::Owner);
    confirmConsoleStepUp();

    $this->get(route('audit-streams.create'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('destinations', fn ($destinations): bool => collect($destinations)->pluck('value')->all() === array_map(static fn (Destination $d): string => $d->value, Destination::cases()))
        ->has('datadogSites', 7));

    createLogStream([
        'name' => 'Datadog',
        'destination' => 'datadog',
        'endpointUrl' => '',
        'scheme' => '',
        'secret' => 'dd-console-api-key',
        'options' => ['site' => 'datadoghq.eu', 'tags' => 'env:prod, team:sec', 'bucket' => 'ignored-for-datadog'],
    ])->assertSessionHasNoErrors()->assertInertiaFlashMissing('newSecret');

    $stream = AuditStream::query()->where('name', 'Datadog')->sole();

    expect($stream->destinationOptions())->toBe(['site' => 'datadoghq.eu', 'tags' => 'env:prod,team:sec']);

    $show = $this->get(route('audit-streams.show', $stream->id))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('stream.health', 'healthy')
        ->where('stream.scheme', null)
        ->where('aws', null)
        ->where('urls.test', route('audit-streams.test', $stream->id)));

    expect((string) $show->getContent())->not->toContain('dd-console-api-key');

    $this->from(route('audit-streams.show', $stream->id))->post(route('audit-streams.test', $stream->id))
        ->assertRedirect(route('audit-streams.show', $stream->id));

    expect(flashed('streamTest'))->toBe(['id' => $stream->id, 'delivered' => true, 'failure' => null, 'error' => null]);

    $edit = $this->get(route('audit-streams.edit', $stream->id))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('stream.hasSecret', true)
        ->where('stream.endpointUrl', '')
        ->where('stream.options.tags', 'env:prod,team:sec'));

    expect((string) $edit->getContent())->not->toContain('dd-console-api-key');

    // Empty secret keeps the key; an emptied option is removed.
    $this->from(route('audit-streams.edit', $stream->id))->patch(route('audit-streams.update', $stream->id), [
        'name' => 'Datadog US5',
        'destination' => 'datadog',
        'endpointUrl' => '',
        'secret' => '',
        'options' => ['site' => 'us5.datadoghq.com', 'tags' => '', 'service' => 'acme'],
    ])->assertSessionHasNoErrors()->assertRedirect(route('audit-streams.show', $stream->id));

    $stream->refresh();

    expect($stream->name)->toBe('Datadog US5')
        ->and($stream->endpoint_url)->toBe('https://http-intake.logs.us5.datadoghq.com/api/v2/logs')
        ->and($stream->destinationOptions())->toBe(['site' => 'us5.datadoghq.com', 'service' => 'acme'])
        ->and($stream->secret)->toBe('dd-console-api-key')
        ->and(AuditEntry::query()->where('action', 'log_stream.updated')->sole()->actor_type->value)->toBe('user');
});

it('puts a refused setting on the console field it is about', function (): void {
    actingAsRole(MembershipRole::Owner);
    confirmConsoleStepUp();

    createLogStream([
        'destination' => 's3',
        'endpointUrl' => '',
        'secret' => 'k',
        'options' => ['bucket' => 'Not A Bucket', 'region' => 'eu-west-1', 'access_key_id' => 'AKIAEXAMPLE'],
    ])->assertSessionHasErrors(['options.bucket']);

    createLogStream([
        'destination' => 'gcs',
        'endpointUrl' => '',
        'secret' => '{"type":"authorized_user"}',
        'options' => ['bucket' => 'acme-audit'],
    ])->assertSessionHasErrors(['secret']);

    expect(AuditStream::query()->count())->toBe(0);
});

it('opens an assumed-role S3 stream on its trust policy in the console', function (): void {
    platformAwsIdentity();
    actingAsRole(MembershipRole::Owner);
    confirmConsoleStepUp();

    createLogStream([
        'name' => 'Archive',
        'destination' => 's3',
        'endpointUrl' => '',
        'secret' => 'typed-but-ignored-for-a-role',
        'options' => ['bucket' => 'acme-audit', 'region' => 'us-gov-west-1', 'role_arn' => 'arn:aws-us-gov:iam::444455556666:role/cbox-writer', 'sse' => 'aws:kms', 'kms_key_id' => 'alias/audit'],
    ])->assertSessionHasErrors(['secret']);

    createLogStream([
        'name' => 'Archive',
        'destination' => 's3',
        'endpointUrl' => '',
        'secret' => '',
        'options' => ['bucket' => 'acme-audit', 'region' => 'us-gov-west-1', 'role_arn' => 'arn:aws-us-gov:iam::444455556666:role/cbox-writer', 'sse' => 'aws:kms', 'kms_key_id' => 'alias/audit'],
    ])->assertSessionHasNoErrors();

    $stream = AuditStream::query()->where('name', 'Archive')->sole();
    $externalId = (string) ($stream->destinationOptions()['external_id'] ?? '');

    expect(flashed('awsSetup'))->toBe($stream->id);

    $this->get(route('audit-streams.show', $stream->id))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('aws.externalId', $externalId)
        ->where('aws.principalConfigured', true)
        ->where('aws.trustPolicy', fn (string $policy): bool => str_contains($policy, '"sts:ExternalId": "'.$externalId.'"')
            && str_contains($policy, 'arn:aws:iam::111122223333:user/cbox-siem'))
        ->where('aws.permissionsPolicy', fn (string $policy): bool => str_contains($policy, 'arn:aws-us-gov:s3:::acme-audit/*')
            && str_contains($policy, 'kms:GenerateDataKey')));
});

it('keeps another owner\'s stream out of reach of the new console doors', function (): void {
    actingAsRole(MembershipRole::Owner);
    confirmConsoleStepUp();

    $operators = app(LogStreams::class)->create('Operator', Destination::GenericJson, 'https://siem.operator.example/collector', null, AuthScheme::None);

    $this->get(route('audit-streams.edit', $operators->stream->id))->assertNotFound();
    $this->patch(route('audit-streams.update', $operators->stream->id), ['name' => 'Mine now', 'destination' => 'generic_json', 'endpointUrl' => 'https://evil.example/in', 'scheme' => 'none'])->assertNotFound();
    $this->post(route('audit-streams.test', $operators->stream->id))->assertNotFound();

    expect(AuditStream::query()->findOrFail($operators->stream->id)->endpoint_url)->toBe('https://siem.operator.example/collector');
})->group('security');
