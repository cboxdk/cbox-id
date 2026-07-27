<?php

declare(strict_types=1);

use Cbox\Id\Compliance\Contracts\AuditExportSink;
use Cbox\Id\Compliance\Export\ExportAuditTrail;
use Cbox\Id\Compliance\Models\AuditExportCursor;
use Cbox\Id\Compliance\Testing\FakeAuditExportSink;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exports only new entries and resumes from the persisted cursor', function (): void {
    $sink = new FakeAuditExportSink;
    app()->instance(AuditExportSink::class, $sink);

    $this->recordAudit('auth.login');
    $this->recordAudit('auth.logout');

    $run = app(ExportAuditTrail::class)->run();

    expect($sink->count())->toBe(2)
        ->and($run->entries_exported)->toBe(2)
        ->and($run->status)->toBe('completed');

    // A second run with nothing new ships nothing.
    $second = app(ExportAuditTrail::class)->run();
    expect($sink->count())->toBe(2)
        ->and($second->entries_exported)->toBe(0);

    // New entries resume from the cursor, not the start.
    $this->recordAudit('token.issued');
    app(ExportAuditTrail::class)->run();

    expect($sink->count())->toBe(3)
        ->and(AuditExportCursor::query()->where('scope', '__system__')->value('last_sequence'))->toBe(3);
});

it('tracks an independent cursor per chain (system trail + organization)', function (): void {
    $sink = new FakeAuditExportSink;
    app()->instance(AuditExportSink::class, $sink);

    $this->recordAudit('system.boot');                  // system trail
    $this->recordAudit('org.updated', 'org_alpha');     // org chain
    $this->recordAudit('org.member_added', 'org_alpha');

    app(ExportAuditTrail::class)->run();

    expect($sink->forScope('__system__'))->toHaveCount(1)
        ->and($sink->forScope('org_alpha'))->toHaveCount(2);

    // Batches carry the exact sequence range they cover.
    $orgBatch = collect($sink->batches())->firstWhere('scope', 'org_alpha');
    expect($orgBatch->fromSequence)->toBe(1)
        ->and($orgBatch->toSequence)->toBe(2);
});

it('preserves the tamper-evidence fields (hash chain) in the exported record', function (): void {
    $sink = new FakeAuditExportSink;
    app()->instance(AuditExportSink::class, $sink);

    $this->recordAudit('auth.login');
    app(ExportAuditTrail::class)->run();

    $record = $sink->records()[0];
    expect($record->sequence)->toBe(1)
        ->and($record->hash)->toHaveLength(64)
        ->and($record->prevHash)->toHaveLength(64);
});
