<?php

declare(strict_types=1);

use Cbox\Id\Compliance\ComplianceServiceProvider;
use Cbox\Id\Compliance\Contracts\AuditExportSink;
use Cbox\Id\Compliance\Sinks\JsonlBundleExportSink;
use Cbox\Id\Compliance\ValueObjects\AuditExportBatch;
use Cbox\Id\Compliance\ValueObjects\AuditExportRecord;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
| The compliance JSONL archive on shared object storage (the `r2` disk).
|
| The scheduler writes the archive, so on a deployment of more than one pod it has to be on
| storage every pod reaches. An object cannot be appended to — `append()` on an S3 disk
| downloads the object and uploads it again — so on object storage the sink writes one
| object per batch, named by its first sequence, and the archive is those objects in order.
*/

/** Bind the sink the way the provider does for this configuration. */
function bindConfiguredComplianceSink(): void
{
    $provider = new ComplianceServiceProvider(app());

    (fn () => $this->registerConfiguredSink())->call($provider);
}

function archiveRecord(string $environmentId, int $sequence): AuditExportRecord
{
    // id, environment, scope, organization, sequence, actor type and id, action, target
    // type and id, context, ip, prev hash, hash, recorded at.
    return new AuditExportRecord(
        (string) Str::ulid(), $environmentId, '__system__', null, $sequence, 'system', null, 'tenant.trail',
        null, null, [], null, str_repeat('0', 64), hash('sha256', (string) $sequence), null,
    );
}

it('archives to the r2 disk one object per batch, which concatenated in order are the bundle', function (): void {
    Storage::fake('r2');
    config([
        'compliance.export.sink' => 'jsonl',
        'compliance.export.jsonl.disk' => 'r2',
        'compliance.export.batch_size' => 2,
    ]);
    bindConfiguredComplianceSink();

    $context = app(EnvironmentContext::class);
    $alpha = Environment::query()->create(['id' => (string) Str::ulid(), 'slug' => 'alpha', 'name' => 'Alpha']);

    $context->runAs($alpha, function (): void {
        foreach (range(1, 5) as $i) {
            app(AuditLog::class)->record(AuditEvent::forSystem('tenant.trail'));
        }
    });

    $context->set(null);
    $this->artisan('id-compliance:export')->assertSuccessful();

    $objects = Storage::disk('r2')->allFiles('compliance/audit/'.$alpha->id);
    sort($objects);

    // Never the appended single file: on object storage that is a whole-archive upload per batch.
    expect($objects)->not->toContain('compliance/audit/'.$alpha->id.'/__system__.jsonl')
        ->and(count($objects))->toBeGreaterThanOrEqual(3);

    $sequences = [];

    foreach ($objects as $object) {
        $lines = array_values(array_filter(explode("\n", (string) Storage::disk('r2')->get($object))));
        $first = json_decode($lines[0], true);

        // Each object is named by the first sequence it holds, zero-padded to list in order.
        expect(basename($object))->toBe(sprintf('%020d.jsonl', $first['sequence']))
            ->and(dirname($object))->toBe('compliance/audit/'.$alpha->id.'/__system__');

        foreach ($lines as $line) {
            $sequences[] = json_decode($line, true)['sequence'];
        }
    }

    // In listing order the objects are the whole chain, once, with no gap.
    expect($sequences)->toBe(range(1, count($sequences)))
        ->and(count($sequences))->toBeGreaterThanOrEqual(5);

    // A second run with nothing new writes nothing.
    $this->artisan('id-compliance:export')->assertSuccessful();

    expect(Storage::disk('r2')->allFiles('compliance/audit/'.$alpha->id))->toHaveCount(count($objects));
});

it('replaces a re-offered batch on object storage instead of archiving its entries twice', function (): void {
    Storage::fake('r2');
    $sink = new JsonlBundleExportSink(Storage::disk('r2'), 'compliance/audit', segmented: true);

    // Written, but the cursor did not move: the engine offers the batch again from the
    // same sequence, by then with one more entry.
    $sink->export(new AuditExportBatch('env_a', '__system__', null, [archiveRecord('env_a', 1), archiveRecord('env_a', 2)], 1, 2));
    $sink->export(new AuditExportBatch('env_a', '__system__', null, [archiveRecord('env_a', 1), archiveRecord('env_a', 2), archiveRecord('env_a', 3)], 1, 3));

    $objects = Storage::disk('r2')->allFiles('compliance/audit');
    $lines = array_values(array_filter(explode("\n", (string) Storage::disk('r2')->get($objects[0]))));

    expect($objects)->toBe(['compliance/audit/env_a/__system__/00000000000000000001.jsonl'])
        ->and(array_map(static fn (string $line): int => json_decode($line, true)['sequence'], $lines))->toBe([1, 2, 3]);
});

it('keeps appending to one bundle file on a local disk', function (): void {
    Storage::fake('local');
    config(['compliance.export.sink' => 'jsonl', 'compliance.export.jsonl.disk' => 'local']);
    bindConfiguredComplianceSink();

    app(AuditExportSink::class)->export(new AuditExportBatch('env_a', '__system__', null, [archiveRecord('env_a', 1)], 1, 1));
    app(AuditExportSink::class)->export(new AuditExportBatch('env_a', '__system__', null, [archiveRecord('env_a', 2)], 2, 2));

    expect(Storage::disk('local')->allFiles('compliance/audit'))->toBe(['compliance/audit/env_a/__system__.jsonl'])
        ->and(substr_count((string) Storage::disk('local')->get('compliance/audit/env_a/__system__.jsonl'), "\n"))->toBe(1);
});
